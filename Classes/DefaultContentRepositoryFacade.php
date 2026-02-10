<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspaces;
use Neos\Flow\Annotations as Flow;

/**
 * Production implementation of {@see ContentRepositoryFacade}.
 *
 * Thin pass-through wrapper around the real ContentRepository. Created in
 * {@see Command\McpCommandController::serverCommand()} where the actual
 * ContentRepository instance is available.
 */
#[Flow\Proxy(false)]
final readonly class DefaultContentRepositoryFacade implements ContentRepositoryFacade
{
    public function __construct(
        private ContentRepository $contentRepository,
    ) {
    }

    public function getId(): ContentRepositoryId
    {
        return $this->contentRepository->id;
    }

    public function getNodeTypeManager(): NodeTypeManager
    {
        return $this->contentRepository->getNodeTypeManager();
    }

    public function getContentGraph(WorkspaceName $workspaceName): ContentGraphInterface
    {
        return $this->contentRepository->getContentGraph($workspaceName);
    }

    public function getContentDimensionSource(): ContentDimensionSourceInterface
    {
        return $this->contentRepository->getContentDimensionSource();
    }

    public function getDimensionSpacePoints(): DimensionSpacePointSet
    {
        return $this->contentRepository->getVariationGraph()->getDimensionSpacePoints();
    }

    public function findWorkspaces(): Workspaces
    {
        return $this->contentRepository->findWorkspaces();
    }

    public function findWorkspaceByName(WorkspaceName $workspaceName): ?Workspace
    {
        return $this->contentRepository->findWorkspaceByName($workspaceName);
    }

    public function handle(CommandInterface $command): void
    {
        $this->contentRepository->handle($command);
    }
}
