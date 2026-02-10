<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\McpToolProvider;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class McpToolProviderTest extends UnitTestCase
{
    private McpToolProvider $subject;
    private ContentRepositoryFacade&MockObject $contentRepository;

    /** @var list<object> */
    private array $handledCommands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentRepository = $this->createMock(ContentRepositoryFacade::class);
        $this->handledCommands = [];

        $this->contentRepository->method('handle')->willReturnCallback(
            function (object $command): void {
                $this->handledCommands[] = $command;
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

        $this->subject = new McpToolProvider(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
        );
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function getWorkspaceStatusReturnsNotFoundForMissingWorkspace(): void
    {
        $this->contentRepository->method('findWorkspaceByName')->willReturn(null);

        $result = $this->subject->getWorkspaceStatus();

        self::assertSame('test-workspace', $result['workspaceName']);
        self::assertSame('not_found', $result['status']);
        self::assertFalse($result['hasPendingChanges']);
    }

    /**
     * @test
     */
    public function getWorkspaceStatusReturnsWorkspaceInfo(): void
    {
        $workspace = Workspace::create(
            WorkspaceName::fromString('test-workspace'),
            WorkspaceName::fromString('live'),
            ContentStreamId::fromString('cs-1'),
            WorkspaceStatus::UP_TO_DATE,
            true,
        );
        $this->contentRepository->method('findWorkspaceByName')->willReturn($workspace);

        $result = $this->subject->getWorkspaceStatus();

        self::assertSame('test-workspace', $result['workspaceName']);
        self::assertSame('live', $result['baseWorkspace']);
        self::assertSame('UP_TO_DATE', $result['status']);
        self::assertTrue($result['hasPendingChanges']);
    }

    /**
     * @test
     */
    public function discardWorkspaceChangesCallsHandle(): void
    {
        $this->handledCommands = [];

        $this->subject->discardWorkspaceChanges();

        self::assertCount(1, $this->handledCommands);
    }

    /**
     * @test
     */
    public function listNodeTypesReturnsResultsForEmptyConfig(): void
    {
        $result = $this->subject->listNodeTypes();

        // NodeTypeManager always includes built-in Neos.ContentRepository:Root
        $names = array_column($result, 'name');
        self::assertContains('Neos.ContentRepository:Root', $names);
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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
