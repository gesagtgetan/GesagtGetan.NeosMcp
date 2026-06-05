<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use GesagtGetan\NeosMcp\Dto\ContentRepositoryInfo;
use GesagtGetan\NeosMcp\Dto\FindAndReplaceRequest;
use GesagtGetan\NeosMcp\Dto\FindAndReplaceResult;
use GesagtGetan\NeosMcp\Dto\FindNodesRequest;
use GesagtGetan\NeosMcp\Dto\MoveResult;
use GesagtGetan\NeosMcp\Dto\NodeInfo;
use GesagtGetan\NeosMcp\Dto\NodeInfoCollection;
use GesagtGetan\NeosMcp\Dto\NodeTypeSchema;
use GesagtGetan\NeosMcp\Dto\NodeTypeSummaryCollection;
use GesagtGetan\NeosMcp\Dto\ReferenceInfoCollection;
use GesagtGetan\NeosMcp\Dto\ReorderNodeRequest;
use GesagtGetan\NeosMcp\Dto\WriteResult;
use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Service\NodeTypeService;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use Neos\Flow\Annotations as Flow;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\ServerBuilder;

/**
 * Built-in MCP tool provider for node-scoped operations (read, write, node-type).
 *
 * Prototype-scoped: {@see McpToolProviderRegistry} resolves a fresh instance per
 * MCP request via `ObjectManager`. The request context (workspace, CR facade,
 * truncation length) is injected through {@see registerTools()} and stored on
 * the instance; tool methods then read it directly. Because each request gets a
 * fresh instance, there is no shared-state risk between concurrent requests.
 *
 * Every public `#[McpTool]` method rebases the workspace before its underlying
 * service call so reads see live state and writes don't target stale nodes;
 * mutating tools forward any rebase-conflict warning on the response payload.
 */
#[Flow\Scope('prototype')]
final class McpNodeToolProvider implements McpToolProvider
{
    private NodeReadService $nodeReadService;
    private NodeWriteService $nodeWriteService;
    private NodeTypeService $nodeTypeService;
    private WorkspaceRebaser $rebaser;

    public function registerTools(
        ServerBuilder $builder,
        BasicContainer $container,
        McpRequestContext $context,
    ): ServerBuilder {
        $this->nodeReadService = new NodeReadService(
            $context->contentRepository,
            $context->workspaceName,
            $context->propertyTruncateLength,
        );
        $this->nodeWriteService = new NodeWriteService(
            $context->contentRepository,
            $context->workspaceName,
            $context->propertyTruncateLength,
        );
        $this->nodeTypeService = new NodeTypeService($context->contentRepository);
        $this->rebaser = new WorkspaceRebaser($context->contentRepository, $context->workspaceName);

        $container->set(self::class, $this);

        return McpToolReflector::register($builder, self::class, $context->disabledTools);
    }

    #[McpTool(
        description: <<<'MCP'
            Returns available dimensions, workspaces, and dimension space points for the content repository.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function getContentRepositoryInfo(): ContentRepositoryInfo
    {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning($this->nodeReadService->getContentRepositoryInfo(), $warning);
    }

    #[McpTool(
        description: <<<'MCP'
            List non-abstract node types with property summaries. Optional filter parameter for name pattern (case-insensitive substring match).
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function listNodeTypes(?string $filter = null): NodeTypeSummaryCollection
    {
        $this->rebaser->rebase();

        return $this->nodeTypeService->listNodeTypes($filter);
    }

    #[McpTool(
        description: <<<'MCP'
            Get full schema for a node type including properties, child nodes, and references.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function getNodeTypeSchema(string $nodeTypeName): NodeTypeSchema
    {
        $this->rebaser->rebase();

        return $this->nodeTypeService->getNodeTypeSchema($nodeTypeName);
    }

    /**
     * @param string|null $nodeTypeName Filter by node type (e.g. 'Neos.Neos:Document')
     * @param string|null $searchTerm Full-text search term — searches across all string properties of matching nodes
     * @param string|null $parentNodeAggregateId Limit search to descendants of this node
     * @param int $limit Maximum number of results (default: 100)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include soft-removed (trashed) nodes that are normally hidden (default: false)
     */
    #[McpTool(
        description: <<<'MCP'
            Search for nodes by type and/or search term. Returns a preview — long string values are truncated. Use getNode() for a node's full, untruncated properties.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function findNodes(
        ?string $nodeTypeName = null,
        ?string $searchTerm = null,
        ?string $parentNodeAggregateId = null,
        int $limit = 100,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): NodeInfoCollection {
        $this->rebaser->rebase();

        return $this->nodeReadService->findNodes(new FindNodesRequest(
            nodeTypeName: $nodeTypeName,
            searchTerm: $searchTerm,
            parentNodeAggregateId: $parentNodeAggregateId,
            limit: $limit,
            dimensionSpacePoint: $dimensionSpacePoint,
            includeRemoved: $includeRemoved,
        ));
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include soft-removed (trashed) nodes that are normally hidden (default: false)
     */
    #[McpTool(
        description: <<<'MCP'
            Get a single node with all its properties by its aggregate ID.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function getNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): ?NodeInfo {
        $warning = $this->rebaser->rebase();
        $node = $this->nodeReadService->getNode($nodeAggregateId, $dimensionSpacePoint, $includeRemoved);

        return $node !== null ? $this->rebaser->withWarning($node, $warning) : null;
    }

    /**
     * @param string $parentNodeAggregateId The parent node aggregate ID
     * @param string|null $nodeTypeName Filter children by node type
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include soft-removed (trashed) nodes that are normally hidden (default: false)
     */
    #[McpTool(
        description: <<<'MCP'
            List child nodes of a parent node. Optionally filter by node type.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function getChildren(
        string $parentNodeAggregateId,
        ?string $nodeTypeName = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): NodeInfoCollection {
        $this->rebaser->rebase();

        return $this->nodeReadService->getChildren(
            $parentNodeAggregateId,
            $nodeTypeName,
            $dimensionSpacePoint,
            $includeRemoved,
        );
    }

    /**
     * @param string $nodeAggregateId The source node aggregate ID
     * @param string|null $referenceName Optional filter — return only references with this name
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include references from/to soft-removed (trashed) nodes (default: false)
     */
    #[McpTool(
        description: <<<'MCP'
            Get outgoing references for a node — which nodes does this node point at? Returns each reference's name, target node, and any edge-properties declared by the reference type.

            References in Neos 9 are graph edges stored separately from a node's scalar properties — getNode() does NOT return them. Use getNodeTypeSchema() to discover which reference names a node type declares.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function getNodeReferences(
        string $nodeAggregateId,
        ?string $referenceName = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): ReferenceInfoCollection {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeReadService->findReferences($nodeAggregateId, $referenceName, $dimensionSpacePoint, $includeRemoved),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to look up back-references for
     * @param string|null $referenceName Optional filter — return only incoming references with this name
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include incoming references from soft-removed (trashed) source nodes (default: false)
     */
    #[McpTool(
        description: <<<'MCP'
            Get incoming references for a node — which other nodes point AT this one? Useful for impact analysis before deletes or moves ("what links to this asset?").

            Each result's `target` field is the SOURCE of the incoming reference (the node doing the pointing). Optional `referenceName` filters to one reference name.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function getNodeBackReferences(
        string $nodeAggregateId,
        ?string $referenceName = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): ReferenceInfoCollection {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeReadService->findBackReferences($nodeAggregateId, $referenceName, $dimensionSpacePoint, $includeRemoved),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The source node aggregate ID
     * @param string $referenceName The reference name to set (must be declared in the source node type)
     * @param list<array{target: string, properties?: array<string, mixed>}> $targets Targets for this reference name. Empty array deletes all references for this name. Each entry is {target: "aggregate-id", properties: {...}}.
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Set the references for one reference name on a node. References for this name are REPLACED (not merged) — pass an empty `targets` array to delete all references for this name.

        Each target is an object with `target` (required: the destination node aggregate ID) and optional `properties` (only used when the reference type declares edge-properties on the reference itself; usually omitted).

        Check getNodeTypeSchema() for the source node type to see which reference names are declared and whether they accept edge-properties. To read the current state, use getNodeReferences().
        MCP)]
    public function setNodeReference(
        string $nodeAggregateId,
        string $referenceName,
        #[Schema(
            type: 'array',
            items: [
                'type' => 'object',
                'properties' => [
                    'target' => ['type' => 'string'],
                    'properties' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'required' => ['target'],
            ],
        )]
        array $targets,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->setNodeReference($nodeAggregateId, $referenceName, $targets, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * @param string $parentNodeAggregateId The parent node aggregate ID
     * @param string $nodeTypeName The node type to create (e.g. 'Neos.Neos:Document')
     * @param array<string, mixed>|null $properties Property values to set on the new node
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Create a new node under a parent node in the workspace. The nodeAggregateId is auto-generated and returned in the response — callers cannot specify it.

        IMPORTANT — before calling createNode(), always verify that the node does not already exist:
        1. Call getNodeTypeSchema() for the parent's node type. Check the `childNodes` field — these are auto-created (tethered) child nodes that already exist when the parent is created. You cannot create them again; use setNodeProperties() to populate their properties instead.
        2. Call getChildren() on the parent to see all current children. Content collections (e.g. a "main" content area) may already contain nodes with empty/null properties. Populate these with setNodeProperties() instead of creating duplicates.
        3. Only call createNode() when a genuinely new node is needed.
        MCP)]
    public function createNode(
        string $parentNodeAggregateId,
        string $nodeTypeName,
        #[Schema(type: 'object', additionalProperties: true)]
        ?array $properties = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->createNode($parentNodeAggregateId, $nodeTypeName, $properties ?? [], $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to update
     * @param array<string, mixed> $properties Property values to set (partial update — omitted properties are left unchanged)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Update properties on an existing node. This is a partial update — only the provided properties are changed, all other properties remain unchanged.
        MCP)]
    public function setNodeProperties(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: true)]
        array $properties,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        if ($properties === []) {
            throw new \InvalidArgumentException('Properties must be a non-empty JSON object.', 1770740199);
        }

        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->setNodeProperties($nodeAggregateId, $properties, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to move
     * @param string $newParentNodeAggregateId The new parent node aggregate ID
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Move a node to a different parent. The node is appended at the end of the new parent's children.

        Use reorderNode instead to change a node's sort order within its current parent (relative to siblings).
        MCP)]
    public function moveNode(
        string $nodeAggregateId,
        string $newParentNodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): MoveResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->moveNode($nodeAggregateId, $newParentNodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to vary
     * @param array<string, string> $sourceDimensionSpacePoint The DSP the node currently exists in, e.g. {"language":"de"}
     * @param array<string, string> $targetDimensionSpacePoint The DSP to materialize the variant into, e.g. {"language":"en"}
     */
    #[McpTool(description: <<<'MCP'
        Materialize an existing node into a different dimension space point so it can be written to or read from that DSP.
        The Content Repository does NOT auto-create nodes in every dimension. Calling `setNodeProperties`, `getNode`, or `findNodes` against a DSP where the node has no variant yet fails with "Dimension space point ... is not yet occupied by node aggregate". Call this tool first to materialize the node into the target DSP, then write to it.
        MCP)]
    public function createNodeVariant(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        array $sourceDimensionSpacePoint,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        array $targetDimensionSpacePoint,
    ): WriteResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->createNodeVariant(
                $nodeAggregateId,
                $sourceDimensionSpacePoint,
                $targetDimensionSpacePoint,
            ),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to reorder
     * @param string|null $placeBeforeNodeAggregateId Place the node directly before this sibling. Provide either this or placeAfterNodeAggregateId (at least one is required).
     * @param string|null $placeAfterNodeAggregateId Place the node directly after this sibling. Provide either this or placeBeforeNodeAggregateId (at least one is required).
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Change a node's sort order within its current parent by placing it relative to a sibling. The parent does NOT change.

        Provide at least one of `placeBeforeNodeAggregateId` or `placeAfterNodeAggregateId`. The target sibling must currently be a child of the same parent.

        Use moveNode instead to move a node to a different parent.
        MCP)]
    public function reorderNode(
        string $nodeAggregateId,
        ?string $placeBeforeNodeAggregateId = null,
        ?string $placeAfterNodeAggregateId = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->reorderNode(new ReorderNodeRequest(
                nodeAggregateId: $nodeAggregateId,
                placeBeforeNodeAggregateId: $placeBeforeNodeAggregateId,
                placeAfterNodeAggregateId: $placeAfterNodeAggregateId,
                dimensionSpacePoint: $dimensionSpacePoint,
            )),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to remove
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(
        description: <<<'MCP'
            Remove a node. This is a soft-delete (trash) — the node can be restored later.

            Use findNodes or getNode with includeRemoved: true to find trashed nodes.
            MCP,
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    public function removeNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->removeNode($nodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to hide
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Hide a node so it is not visible on the public site. This is reversible — use unhideNode to make it visible again.
        MCP)]
    public function hideNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->hideNode($nodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * @param string $nodeAggregateId The node aggregate ID to unhide
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Unhide a previously hidden node so it becomes visible on the public site again.
        MCP)]
    public function unhideNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->unhideNode($nodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * @param string $search The string to search for
     * @param string $replace The replacement string
     * @param string|null $nodeTypeName Optional filter: restrict to this node type (e.g. 'Neos.Neos:Document'). Omit to search all node types.
     * @param string|null $propertyName Optional filter: restrict to this property. Omit to search all string properties of each node.
     * @param bool $dryRun If true, only report matches without making changes (default: false)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     */
    #[McpTool(description: <<<'MCP'
        Find and replace a string across the content tree. Without filters, searches all node types and all string properties. Use nodeTypeName and/or propertyName to narrow scope.
        MCP)]
    public function findAndReplace(
        string $search,
        string $replace,
        ?string $nodeTypeName = null,
        ?string $propertyName = null,
        bool $dryRun = false,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): FindAndReplaceResult {
        $warning = $this->rebaser->rebase();

        return $this->rebaser->withWarning(
            $this->nodeWriteService->findAndReplace(new FindAndReplaceRequest(
                search: $search,
                replace: $replace,
                nodeTypeName: $nodeTypeName,
                propertyName: $propertyName,
                dryRun: $dryRun,
                dimensionSpacePoint: $dimensionSpacePoint,
            )),
            $warning,
        );
    }
}
