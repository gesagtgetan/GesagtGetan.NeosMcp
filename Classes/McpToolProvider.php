<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp;

use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Service\NodeTypeService;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use GesagtGetan\NeosMcp\Service\RedirectService;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

#[Flow\Proxy(false)]
final readonly class McpToolProvider
{
    private NodeTypeService $nodeTypeService;
    private NodeReadService $nodeReadService;
    private NodeWriteService $nodeWriteService;
    private RedirectService $redirectService;

    public function __construct(
        private ContentRepositoryFacade $contentRepository,
        private WorkspaceName $workspaceName,
        RedirectStorageInterface $redirectStorage,
    ) {
        $this->nodeTypeService = new NodeTypeService($this->contentRepository);
        $this->nodeReadService = new NodeReadService($this->contentRepository, $this->workspaceName);
        $this->nodeWriteService = new NodeWriteService($this->contentRepository, $this->workspaceName);
        $this->redirectService = new RedirectService($redirectStorage);
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
        return $this->nodeReadService->getContentRepositoryInfo();
    }

    /**
     * List non-abstract node types with property summaries. Optional filter parameter for name pattern (case-insensitive substring match).
     *
     * @return array<int, mixed>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function listNodeTypes(?string $filter = null): array
    {
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
        return $this->nodeTypeService->getNodeTypeSchema($nodeTypeName);
    }

    /**
     * Search for nodes by type and/or search term. Returns matching nodes with all properties.
     *
     * @param string|null $nodeTypeName Filter by node type (e.g. 'Neos.Neos:Document')
     * @param string|null $searchTerm Full-text search term
     * @param string|null $parentNodeAggregateId Limit search to descendants of this node
     * @param int $limit Maximum number of results (default: 100)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
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
    ): array {
        return $this->nodeReadService->findNodes(
            $nodeTypeName,
            $searchTerm,
            $parentNodeAggregateId,
            $limit,
            $dimensionSpacePoint,
        );
    }

    /**
     * Get a single node with all its properties by its aggregate ID.
     *
     * @param string $nodeAggregateId The node aggregate ID
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
     *
     * @return array<string, mixed>|null
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): ?array {
        return $this->nodeReadService->getNode(
            $nodeAggregateId,
            $dimensionSpacePoint,
        );
    }

    /**
     * List child nodes of a parent node. Optionally filter by node type.
     *
     * @param string $parentNodeAggregateId The parent node aggregate ID
     * @param string|null $nodeTypeName Filter children by node type
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
     *
     * @return array<int, mixed>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getChildren(
        string $parentNodeAggregateId,
        ?string $nodeTypeName = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        return $this->nodeReadService->getChildren(
            $parentNodeAggregateId,
            $nodeTypeName,
            $dimensionSpacePoint,
        );
    }

    // ── Write Tools ─────────────────────────────────────────────────

    /**
     * Create a new node under a parent node in the review workspace.
     *
     * @param string $parentNodeAggregateId The parent node aggregate ID
     * @param string $nodeTypeName The node type to create (e.g. 'Neos.Neos:Document')
     * @param array<string, mixed>|null $properties Property values to set on the new node
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
     *
     * @return array{nodeAggregateId: string, success: true}
     */
    #[McpTool]
    public function createNode(
        string $parentNodeAggregateId,
        string $nodeTypeName,
        #[Schema(type: 'object', additionalProperties: true)]
        ?array $properties = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        return $this->nodeWriteService->createNode(
            $parentNodeAggregateId,
            $nodeTypeName,
            $properties ?? [],
            $dimensionSpacePoint,
        );
    }

    /**
     * Update properties on an existing node in the review workspace.
     *
     * @param string $nodeAggregateId The node aggregate ID to update
     * @param array<string, mixed> $properties Property values to set
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
     *
     * @return array{nodeAggregateId: string, success: true}
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

        return $this->nodeWriteService->setNodeProperties(
            $nodeAggregateId,
            $properties,
            $dimensionSpacePoint,
        );
    }

    /**
     * Move a node to a new parent in the review workspace.
     *
     * @param string $nodeAggregateId The node aggregate ID to move
     * @param string $newParentNodeAggregateId The new parent node aggregate ID
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
     *
     * @return array{nodeAggregateId: string, newParentNodeAggregateId: string, success: true}
     */
    #[McpTool]
    public function moveNode(
        string $nodeAggregateId,
        string $newParentNodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        return $this->nodeWriteService->moveNode(
            $nodeAggregateId,
            $newParentNodeAggregateId,
            $dimensionSpacePoint,
        );
    }

    /**
     * Remove a node from the review workspace.
     *
     * @param string $nodeAggregateId The node aggregate ID to remove
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
     *
     * @return array{nodeAggregateId: string, success: true}
     */
    #[McpTool(annotations: new ToolAnnotations(destructiveHint: true))]
    public function removeNode(
        string $nodeAggregateId,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        return $this->nodeWriteService->removeNode(
            $nodeAggregateId,
            $dimensionSpacePoint,
        );
    }

    /**
     * Find all nodes of a type where a property contains a search string and replace it. Useful for batch renaming.
     *
     * @param string $nodeTypeName The node type to search in
     * @param string $propertyName The property name to search
     * @param string $search The string to search for
     * @param string $replace The replacement string
     * @param bool $dryRun If true, only report matches without making changes (default: false)
     * @param array<string, string>|null $dimensionSpacePoint Dimension space point, e.g. {"language":"de"}
     *
     * @return array{affectedNodes: int, matches: list<array{nodeAggregateId: string, oldValue: mixed, newValue: string}>, dryRun: bool}
     */
    #[McpTool]
    public function findAndReplaceProperty(
        string $nodeTypeName,
        string $propertyName,
        string $search,
        string $replace,
        bool $dryRun = false,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $dimensionSpacePoint = null,
    ): array {
        return $this->nodeWriteService->findAndReplaceProperty(
            $nodeTypeName,
            $propertyName,
            $search,
            $replace,
            $dryRun,
            $dimensionSpacePoint,
        );
    }

    // ── Workspace Tools ─────────────────────────────────────────────

    /**
     * Show the review workspace status including pending change count.
     *
     * @return array{workspaceName: string, baseWorkspace: ?string, status: string, hasPendingChanges: bool}
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getWorkspaceStatus(): array
    {
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
     * Discard all pending changes in the review workspace.
     *
     * @return array{workspaceName: string, success: true}
     */
    #[McpTool(annotations: new ToolAnnotations(destructiveHint: true))]
    public function discardWorkspaceChanges(): array
    {
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

    // ── Redirect Tools ──────────────────────────────────────────────

    /**
     * List HTTP redirects. Optionally filter by host and/or substring match on source/target paths.
     *
     * @param string|null $host Filter by host (null = all hosts)
     * @param string|null $match Substring filter on source/target URI paths (case-insensitive)
     * @param int $limit Maximum number of results (default: 100)
     *
     * @return list<array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: ?string, creator: ?string, comment: ?string, type: string, hitCounter: int}>
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function listRedirects(?string $host = null, ?string $match = null, int $limit = 100): array
    {
        return $this->redirectService->listRedirects($host, $match, $limit);
    }

    /**
     * Get a single HTTP redirect by its source URI path and optional host.
     *
     * @param string $sourceUriPath The source URI path (e.g. "old/page")
     * @param string|null $host Filter by host (null = host-independent redirect)
     *
     * @return array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: ?string, creator: ?string, comment: ?string, type: string, hitCounter: int}|null
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getRedirect(string $sourceUriPath, ?string $host = null): ?array
    {
        return $this->redirectService->getRedirect($sourceUriPath, $host);
    }

    /**
     * Create an HTTP redirect. Useful after renaming a page's URI segment.
     *
     * @param string $sourceUriPath The source URI path to redirect from (e.g. "old/page")
     * @param string $targetUriPath The target URI path to redirect to (e.g. "new/page")
     * @param int $statusCode HTTP status code (default: 301)
     * @param string|null $host Restrict redirect to a specific host (null = all hosts)
     * @param string|null $comment Optional comment describing why the redirect was created
     *
     * @return array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: ?string, success: true}
     */
    #[McpTool]
    public function createRedirect(
        string $sourceUriPath,
        string $targetUriPath,
        int $statusCode = 301,
        ?string $host = null,
        ?string $comment = null,
    ): array {
        return $this->redirectService->createRedirect($sourceUriPath, $targetUriPath, $statusCode, $host, $comment);
    }

    /**
     * Remove an HTTP redirect by its source URI path and optional host.
     *
     * @param string $sourceUriPath The source URI path of the redirect to remove
     * @param string|null $host Host filter (null = host-independent redirect)
     *
     * @return array{sourceUriPath: string, host: ?string, success: true}
     */
    #[McpTool(annotations: new ToolAnnotations(destructiveHint: true))]
    public function removeRedirect(string $sourceUriPath, ?string $host = null): array
    {
        return $this->redirectService->removeRedirect($sourceUriPath, $host);
    }
}
