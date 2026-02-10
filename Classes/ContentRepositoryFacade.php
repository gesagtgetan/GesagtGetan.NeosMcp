<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
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
 * Narrow facade over Neos\ContentRepository\Core\ContentRepository.
 *
 * WHY: ContentRepository is a final class, which means PHPUnit cannot mock it.
 * All MCP services depend on this interface instead of the concrete class, so
 * unit tests can substitute a mock without needing the full framework.
 *
 * The interface exposes only the subset of ContentRepository's API that the MCP
 * services actually use. In production, {@see DefaultContentRepositoryFacade}
 * delegates every call to the real ContentRepository.
 */
#[Flow\Proxy(false)]
interface ContentRepositoryFacade
{
    public function getId(): ContentRepositoryId;

    public function getNodeTypeManager(): NodeTypeManager;

    public function getContentGraph(WorkspaceName $workspaceName): ContentGraphInterface;

    public function getContentDimensionSource(): ContentDimensionSourceInterface;

    public function getDimensionSpacePoints(): DimensionSpacePointSet;

    public function findWorkspaces(): Workspaces;

    public function findWorkspaceByName(WorkspaceName $workspaceName): ?Workspace;

    public function handle(CommandInterface $command): void;
}
