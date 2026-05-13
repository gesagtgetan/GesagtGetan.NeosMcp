<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Tool;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Tool\McpNodeToolProvider;
use GesagtGetan\NeosMcp\Tool\McpRequestContext;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandSkipped;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Tests\UnitTestCase;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

class McpNodeToolProviderTest extends UnitTestCase
{
    private McpNodeToolProvider $subject;
    private ContentRepositoryFacade&MockObject $contentRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentRepository = $this->createMock(ContentRepositoryFacade::class);

        // Rebase commands trigger WorkspaceCommandSkipped (the "already up-to-date"
        // happy path); other commands are ignored — these tests don't exercise writes.
        $this->contentRepository->method('handle')->willReturnCallback(
            static function (object $command): void {
                if ($command instanceof RebaseWorkspace) {
                    throw new WorkspaceCommandSkipped();
                }
            },
        );

        $dsp = DimensionSpacePoint::fromArray(['language' => 'de']);
        $this->contentRepository->method('getDimensionSpacePoints')
            ->willReturn(new DimensionSpacePointSet([$dsp]));

        $nodeTypeManager = NodeTypeManager::createFromArrayConfiguration([]);
        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $dimensionSource = $this->createMock(ContentDimensionSourceInterface::class);
        $dimensionSource->method('getContentDimensionsOrderedByPriority')->willReturn([]);
        $this->contentRepository->method('getContentDimensionSource')->willReturn($dimensionSource);

        $this->subject = new McpNodeToolProvider();
        $this->subject->registerTools(
            Server::make()->withServerInfo('test', '0.0.0'),
            new BasicContainer(),
            new McpRequestContext($this->contentRepository, WorkspaceName::fromString('test-workspace')),
        );
    }

    #[Test]
    public function setNodePropertiesRejectsEmptyProperties(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1770740199);

        $this->subject->setNodeProperties('node-id', []);
    }

    #[Test]
    public function listNodeTypesReturnsResultsForEmptyConfig(): void
    {
        $result = $this->subject->listNodeTypes();

        // NodeTypeManager always includes built-in Neos.ContentRepository:Root
        $names = array_column($result, 'name');
        self::assertContains('Neos.ContentRepository:Root', $names);
    }

    #[Test]
    public function findNodesHandlesNullDimensionSpacePoint(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);
        $subgraph->method('findRootNodeByType')->willReturn(null);

        $result = $this->subject->findNodes(dimensionSpacePoint: null);

        self::assertSame([], $result);
    }

    #[Test]
    public function findNodesAcceptsDimensionSpacePointArray(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);
        $subgraph->method('findRootNodeByType')->willReturn(null);

        $result = $this->subject->findNodes(dimensionSpacePoint: ['language' => 'de']);

        self::assertSame([], $result);
    }
}
