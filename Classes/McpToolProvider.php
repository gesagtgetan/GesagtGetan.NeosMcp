<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp;

use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Service\NodeTypeService;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandSkipped;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\ServerBuilder;

#[Flow\Proxy(false)]
final readonly class McpToolProvider
{
    public const string INSTRUCTIONS = <<<'TXT'
        Tools utilize the Neos CMS 9 Content Repository — a typed node tree with workspaces and optional dimensions (e.g. language). Writes target the user's personal workspace (HTTP) or a shared review workspace (CLI) and are not live until published. Warn the user if you are unsure how to use a tool or unfamiliar with the Neos 9 Content Repository and its node type handling.
        TXT;

    private NodeTypeService $nodeTypeService;
    private NodeReadService $nodeReadService;
    private NodeWriteService $nodeWriteService;

    public function __construct(
        private ContentRepositoryFacade $contentRepository,
        private WorkspaceName $workspaceName,
    ) {
        $this->nodeTypeService = new NodeTypeService($this->contentRepository);
        $this->nodeReadService = new NodeReadService($this->contentRepository, $this->workspaceName);
        $this->nodeWriteService = new NodeWriteService($this->contentRepository, $this->workspaceName);
    }

    /**
     * Register all #[McpTool]-annotated methods with the server builder.
     *
     * Uses manual withTool() registration instead of the library's auto-discovery
     * because discovery calls Registry::clear() and cannot be mixed with manual setup.
     * The attribute's description and annotations are forwarded explicitly since
     * withTool() does not read #[McpTool] attributes on its own.
     */
    public static function registerTools(ServerBuilder $builder): ServerBuilder
    {
        foreach ((new \ReflectionClass(self::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(McpTool::class);
            if ($attributes === []) {
                continue;
            }

            /** @var McpTool $attr */
            $attr = $attributes[0]->newInstance();
            $builder = $builder->withTool(
                [self::class, $method->getName()],
                description: $attr->description,
                annotations: $attr->annotations,
            );
        }

        return $builder;
    }

    /**
     * Rebases the workspace onto its base so reads reflect the latest live state
     * and writes don't target stale nodes. Returns a conflict warning string when
     * the rebase fails due to conflicting unpublished changes, or null on success.
     */
    private function rebaseWorkspace(): ?string
    {
        try {
            $this->contentRepository->handle(RebaseWorkspace::create($this->workspaceName));
        } catch (WorkspaceCommandSkipped) {
            // Workspace is already up-to-date — nothing to do.
        } catch (WorkspaceRebaseFailed $e) {
            $conflicts = $e->conflictingEvents->map(static function ($conflict): array {
                $entry = ['message' => $conflict->getException()->getMessage()];
                $nodeId = $conflict->getAffectedNodeAggregateId();
                if ($nodeId !== null) {
                    $entry['nodeAggregateId'] = $nodeId->value;
                }

                return $entry;
            });

            return 'Workspace rebase failed due to conflicts with live. '
                . 'The workspace may contain stale data. '
                . 'Consider discarding conflicting changes via discardWorkspaceChanges. '
                . 'Conflicts: ' . json_encode($conflicts, JSON_THROW_ON_ERROR);
        }

        return null;
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param T $result
     *
     * @return T
     */
    private function withRebaseWarning(array $result, ?string $warning): array
    {
        if ($warning !== null) {
            $result['_rebaseWarning'] = $warning;
        }

        return $result; // @phpstan-ignore return.type (mutation adds _rebaseWarning but T shape is preserved at call sites)
    }

    // ── Read Tools ──────────────────────────────────────────────────

    /**
     * Returns available dimensions, workspaces, and dimension space points for the content repository.
     *
     * @return array<string, mixed>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getContentRepositoryInfo(): array
    {
        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning($this->nodeReadService->getContentRepositoryInfo(), $warning);
    }

    /**
     * List non-abstract node types with property summaries. Optional filter parameter for name pattern (case-insensitive substring match).
     *
     * @return array<int, mixed>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function listNodeTypes(?string $filter = null): array
    {
        $this->rebaseWorkspace();

        return $this->nodeTypeService->listNodeTypes($filter);
    }

    /**
     * Get full schema for a node type including properties, child nodes, and references.
     *
     * @return array<string, mixed>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getNodeTypeSchema(string $nodeTypeName): array
    {
        $this->rebaseWorkspace();

        return $this->nodeTypeService->getNodeTypeSchema($nodeTypeName);
    }

    /**
     * Search for nodes by type and/or search term. Returns matching nodes with all properties.
     *
     * @param string|null $nodeTypeName Filter by node type (e.g. 'Neos.Neos:Document')
     * @param string|null $searchTerm Full-text search term — searches across all string properties of matching nodes
     * @param string|null $parentNodeAggregateId Limit search to descendants of this node
     * @param int $limit Maximum number of results (default: 100)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include soft-removed (trashed) nodes that are normally hidden (default: false)
     *
     * @return array<int, mixed>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function findNodes(
        ?string $nodeTypeName = null,
        ?string $searchTerm = null,
        ?string $parentNodeAggregateId = null,
        int $limit = 100,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): array {
        $this->rebaseWorkspace();

        return $this->nodeReadService->findNodes(
            $nodeTypeName,
            $searchTerm,
            $parentNodeAggregateId,
            $limit,
            $dimensionSpacePoint,
            $includeRemoved,
        );
    }

    /**
     * Get a single node with all its properties by its aggregate ID.
     *
     * @param string $nodeAggregateId The node aggregate ID
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include soft-removed (trashed) nodes that are normally hidden (default: false)
     *
     * @return array<string, mixed>|null
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): ?array {
        $warning = $this->rebaseWorkspace();
        $node = $this->nodeReadService->getNode($nodeAggregateId, $dimensionSpacePoint, $includeRemoved);

        return $node !== null ? $this->withRebaseWarning($node, $warning) : null;
    }

    /**
     * List child nodes of a parent node. Optionally filter by node type.
     *
     * @param string $parentNodeAggregateId The parent node aggregate ID
     * @param string|null $nodeTypeName Filter children by node type
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     * @param bool $includeRemoved Include soft-removed (trashed) nodes that are normally hidden (default: false)
     *
     * @return array<int, mixed>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getChildren(
        string $parentNodeAggregateId,
        ?string $nodeTypeName = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): array {
        $this->rebaseWorkspace();

        return $this->nodeReadService->getChildren(
            $parentNodeAggregateId,
            $nodeTypeName,
            $dimensionSpacePoint,
            $includeRemoved,
        );
    }

    // ── Write Tools ─────────────────────────────────────────────────

    /**
     * @param string $parentNodeAggregateId The parent node aggregate ID
     * @param string $nodeTypeName The node type to create (e.g. 'Neos.Neos:Document')
     * @param array<string, mixed>|null $properties Property values to set on the new node
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     *
     * @return array{nodeAggregateId: string, success: true, _rebaseWarning?: string}
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
    ): array {
        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning(
            $this->nodeWriteService->createNode($parentNodeAggregateId, $nodeTypeName, $properties ?? [], $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * Update properties on an existing node. This is a partial update — only the provided properties are changed, all other properties remain unchanged.
     *
     * @param string $nodeAggregateId The node aggregate ID to update
     * @param array<string, mixed> $properties Property values to set (partial update — omitted properties are left unchanged)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     *
     * @return array{nodeAggregateId: string, success: true, _rebaseWarning?: string}
     */
    #[McpTool]
    public function setNodeProperties(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: true)]
        array $properties,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        if ($properties === []) {
            throw new \InvalidArgumentException('Properties must be a non-empty JSON object.', 1770740199);
        }

        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning(
            $this->nodeWriteService->setNodeProperties($nodeAggregateId, $properties, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * Move a node to a new parent.
     *
     * @param string $nodeAggregateId The node aggregate ID to move
     * @param string $newParentNodeAggregateId The new parent node aggregate ID
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     *
     * @return array{nodeAggregateId: string, newParentNodeAggregateId: string, success: true, _rebaseWarning?: string}
     */
    #[McpTool]
    public function moveNode(
        string $nodeAggregateId,
        string $newParentNodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning(
            $this->nodeWriteService->moveNode($nodeAggregateId, $newParentNodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * Remove a node. This is a soft-delete (trash) — the node can be restored later.
     * Use findNodes or getNode with includeRemoved: true to find trashed nodes.
     *
     * @param string $nodeAggregateId The node aggregate ID to remove
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     *
     * @return array{nodeAggregateId: string, success: true, _rebaseWarning?: string}
     */
    #[McpTool(annotations: new ToolAnnotations(destructiveHint: true))]
    public function removeNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning(
            $this->nodeWriteService->removeNode($nodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * Hide a node so it is not visible on the public site. This is reversible — use unhideNode to make it visible again.
     *
     * @param string $nodeAggregateId The node aggregate ID to hide
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     *
     * @return array{nodeAggregateId: string, success: true, _rebaseWarning?: string}
     */
    #[McpTool]
    public function hideNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning(
            $this->nodeWriteService->hideNode($nodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * Unhide a previously hidden node so it becomes visible on the public site again.
     *
     * @param string $nodeAggregateId The node aggregate ID to unhide
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     *
     * @return array{nodeAggregateId: string, success: true, _rebaseWarning?: string}
     */
    #[McpTool]
    public function unhideNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning(
            $this->nodeWriteService->unhideNode($nodeAggregateId, $dimensionSpacePoint),
            $warning,
        );
    }

    /**
     * Find and replace a string across the content tree. Without filters, searches all node types and all string properties. Use nodeTypeName and/or propertyName to narrow scope.
     *
     * @param string $search The string to search for
     * @param string $replace The replacement string
     * @param string|null $nodeTypeName Optional filter: restrict to this node type (e.g. 'Neos.Neos:Document'). Omit to search all node types.
     * @param string|null $propertyName Optional filter: restrict to this property. Omit to search all string properties of each node.
     * @param bool $dryRun If true, only report matches without making changes (default: false)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}. When omitted, the first configured dimension space point is used as default.
     *
     * @return array{affectedNodes: int, matches: list<array{nodeAggregateId: string, nodeTypeName: string, propertyName: string, oldValue: string, newValue: string}>, dryRun: bool, _rebaseWarning?: string}
     */
    #[McpTool]
    public function findAndReplace(
        string $search,
        string $replace,
        ?string $nodeTypeName = null,
        ?string $propertyName = null,
        bool $dryRun = false,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        $warning = $this->rebaseWorkspace();

        return $this->withRebaseWarning(
            $this->nodeWriteService->findAndReplace($search, $replace, $nodeTypeName, $propertyName, $dryRun, $dimensionSpacePoint),
            $warning,
        );
    }

    // ── Workspace Tools ─────────────────────────────────────────────

    /**
     * Show the workspace status including pending change count.
     *
     * @return array{workspaceName: string, baseWorkspace: ?string, status: string, hasPendingChanges: bool}
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getWorkspaceStatus(): array
    {
        $this->rebaseWorkspace();
        $workspace = $this->contentRepository->findWorkspaceByName($this->workspaceName);
        if ($workspace === null) {
            return [
                'workspaceName' => $this->workspaceName->value,
                'baseWorkspace' => null,
                'status' => 'not_found',
                'hasPendingChanges' => false,
            ];
        }

        return [
            'workspaceName' => $workspace->workspaceName->value,
            'baseWorkspace' => $workspace->baseWorkspaceName?->value,
            'status' => $workspace->status->value,
            'hasPendingChanges' => $workspace->hasPublishableChanges(),
        ];
    }

    /**
     * Discard all pending changes in the workspace.
     *
     * @return array{workspaceName: string, success: true}
     */
    #[McpTool(annotations: new ToolAnnotations(destructiveHint: true))]
    public function discardWorkspaceChanges(): array
    {
        $this->rebaseWorkspace();
        $this->contentRepository->handle(
            \Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\DiscardWorkspace::create(
                $this->workspaceName,
            ),
        );

        return [
            'workspaceName' => $this->workspaceName->value,
            'success' => true,
        ];
    }
}
