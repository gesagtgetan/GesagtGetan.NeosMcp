<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Command;

use GesagtGetan\NeosMcp\Tests\Functional\AbstractFunctionalTest;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\Domain\Model\WorkspaceClassification;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Repository\WorkspaceMetadataAndRoleRepository;
use Neos\Neos\Domain\Service\WorkspaceService;

class McpCommandControllerTest extends AbstractFunctionalTest
{
    private WorkspaceService $workspaceService;
    private WorkspaceMetadataAndRoleRepository $metadataRepository;
    private ContentRepositoryId $crId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaceService = $this->objectManager->get(WorkspaceService::class);
        $this->metadataRepository = $this->objectManager->get(WorkspaceMetadataAndRoleRepository::class);
        $this->crId = ContentRepositoryId::fromString('default');

        // The CR event store is pruned by the parent, but Neos workspace metadata
        // lives in a separate Doctrine table that persists across test runs.
        // Clean up stale metadata to avoid duplicate key errors.
        $workspaceName = WorkspaceName::fromString('llm-review');
        $this->metadataRepository->deleteWorkspaceMetadata($this->crId, $workspaceName);
        $this->metadataRepository->deleteWorkspaceRoleAssignments($this->crId, $workspaceName);
    }

    /**
     * @test
     */
    public function setupCreatesSharedWorkspaceWithNeosMetadata(): void
    {
        $workspaceName = WorkspaceName::fromString('llm-review');

        $this->workspaceService->createSharedWorkspace(
            $this->crId,
            $workspaceName,
            new WorkspaceTitle('LLM Review'),
            new WorkspaceDescription('Review workspace for MCP-generated content changes'),
            WorkspaceName::forLive(),
            WorkspaceRoleAssignments::create(
                WorkspaceRoleAssignment::createForGroup(
                    'Neos.Neos:AbstractEditor',
                    WorkspaceRole::COLLABORATOR,
                ),
            ),
        );

        $workspace = $this->contentRepository->findWorkspaceByName($workspaceName);
        self::assertNotNull($workspace, 'Workspace must exist at CR level');
        self::assertSame('live', $workspace->baseWorkspaceName?->value);

        $metadata = $this->workspaceService->getWorkspaceMetadata($this->crId, $workspaceName);
        self::assertSame('LLM Review', $metadata->title->value);
        self::assertSame('Review workspace for MCP-generated content changes', $metadata->description->value);
        self::assertSame(WorkspaceClassification::SHARED, $metadata->classification);

        $roles = $this->workspaceService->getWorkspaceRoleAssignments($this->crId, $workspaceName);
        self::assertCount(1, $roles);
        self::assertTrue(
            $roles->contains(
                WorkspaceRoleAssignment::createForGroup('Neos.Neos:AbstractEditor', WorkspaceRole::COLLABORATOR),
            ),
        );
    }
}
