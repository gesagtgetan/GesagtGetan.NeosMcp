<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional;

use GesagtGetan\NeosMcp\McpToolProvider;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishWorkspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;

/**
 * Tests that McpToolProvider rebases the workspace before tool calls.
 */
class McpToolProviderRebaseTest extends AbstractFunctionalTest
{
    private McpToolProvider $toolProvider;
    private WorkspaceName $workspaceName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaceName = WorkspaceName::fromString('test-mcp-workspace');
        $this->createTestWorkspace($this->workspaceName);

        $redirectStorage = $this->createMock(RedirectStorageInterface::class);
        $this->toolProvider = new McpToolProvider($this->facade, $this->workspaceName, $redirectStorage);
    }

    /**
     * @test
     *
     * Verifies that a tool call triggers a workspace rebase, picking up live
     * changes that happened after the workspace was created.
     */
    public function toolCallRebasesWorkspaceToReflectLiveChanges(): void
    {
        // Create a node in the MCP workspace and publish it to live.
        $writeService = new NodeWriteService($this->facade, $this->workspaceName);
        $created = $writeService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Published then deleted'],
        );
        $nodeId = $created['nodeAggregateId'];

        $this->contentRepository->handle(
            PublishWorkspace::create($this->workspaceName),
        );

        // Delete the node in live — the workspace doesn't know about this yet.
        $liveWriteService = new NodeWriteService($this->facade, WorkspaceName::forLive());
        $liveWriteService->removeNode($nodeId);

        // A tool call should trigger a rebase, making the deleted node invisible.
        $result = $this->toolProvider->findNodes(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
        );

        $ids = array_column($result, 'nodeAggregateId');
        self::assertNotContains($nodeId, $ids, 'Node deleted in live must not be visible after tool-triggered rebase');
    }

    /**
     * @test
     *
     * When the workspace has unpublished changes that conflict with live, the
     * rebase fails and the tool response must include a _rebaseWarning.
     */
    public function toolCallReturnsRebaseWarningOnConflict(): void
    {
        // Create a node in live.
        $liveWriteService = new NodeWriteService($this->facade, WorkspaceName::forLive());
        $created = $liveWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Original'],
        );
        $nodeId = $created['nodeAggregateId'];

        // Rebase the MCP workspace so it sees the live node.
        $this->contentRepository->handle(
            \Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace::create($this->workspaceName),
        );

        // Modify the node in the MCP workspace (unpublished change).
        $writeService = new NodeWriteService($this->facade, $this->workspaceName);
        $writeService->setNodeProperties($nodeId, ['title' => 'Modified in workspace']);

        // Delete the same node in live — this creates a conflict.
        $liveWriteService->removeNode($nodeId);

        // A tool call should attempt rebase, fail with conflict, and include a warning.
        $result = $this->toolProvider->getNode($nodeId);

        self::assertNotNull($result, 'Node should still be visible in stale workspace');
        self::assertArrayHasKey('_rebaseWarning', $result, 'Tool response must include _rebaseWarning on conflict');
        self::assertIsString($result['_rebaseWarning']);
        self::assertStringContainsString('conflicts', strtolower($result['_rebaseWarning']));
    }
}
