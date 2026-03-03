<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Serializer\Serializer;

class NodeWriteServiceTest extends UnitTestCase
{
    private NodeWriteService $subject;
    private ContentRepositoryFacade&MockObject $contentRepository;
    private PropertyConverter $propertyConverter;

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

        $serializer = $this->getMockBuilder(Serializer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $serializer->method('denormalize')->willReturnCallback(
            static fn (mixed $data): mixed => $data,
        );
        $this->propertyConverter = new PropertyConverter($serializer);

        $this->subject = new NodeWriteService(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
        );
    }

    /**
     * @test
     */
    public function createNodeCallsHandleWithCreateCommand(): void
    {
        $result = $this->subject->createNode(
            'parent-id',
            'Vendor:Content.Text',
            ['text' => 'Hello'],
        );

        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(CreateNodeAggregateWithNode::class, $this->handledCommands[0]);
        self::assertTrue($result['success']);
        self::assertNotEmpty($result['nodeAggregateId']);
    }

    /**
     * @test
     */
    public function createNodeReturnsGeneratedAggregateId(): void
    {
        $result = $this->subject->createNode('parent-id', 'Vendor:Content.Text', []);

        self::assertArrayHasKey('nodeAggregateId', $result);
        self::assertNotEmpty($result['nodeAggregateId']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result['nodeAggregateId'],
        );
    }

    /**
     * @test
     */
    public function setNodePropertiesCallsHandleWithSetCommand(): void
    {
        $result = $this->subject->setNodeProperties(
            'node-id',
            ['title' => 'Updated Title'],
        );

        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(SetNodeProperties::class, $this->handledCommands[0]);
        self::assertSame('node-id', $result['nodeAggregateId']);
        self::assertTrue($result['success']);
    }

    /**
     * @test
     */
    public function moveNodeCallsHandleWithMoveCommand(): void
    {
        $result = $this->subject->moveNode('node-id', 'new-parent-id');

        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(MoveNodeAggregate::class, $this->handledCommands[0]);
        self::assertSame('node-id', $result['nodeAggregateId']);
        self::assertSame('new-parent-id', $result['newParentNodeAggregateId']);
        self::assertTrue($result['success']);
    }

    /**
     * @test
     */
    public function removeNodeCallsHandleWithTagSubtreeCommand(): void
    {
        $result = $this->subject->removeNode('node-id');

        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(TagSubtree::class, $this->handledCommands[0]);
        self::assertSame('node-id', $result['nodeAggregateId']);
        self::assertTrue($result['success']);
    }

    /**
     * @test
     */
    public function hideNodeCallsHandleWithTagSubtreeCommand(): void
    {
        $result = $this->subject->hideNode('node-id');

        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(TagSubtree::class, $this->handledCommands[0]);
        self::assertSame('node-id', $result['nodeAggregateId']);
        self::assertTrue($result['success']);
    }

    /**
     * @test
     */
    public function unhideNodeCallsHandleWithUntagSubtreeCommand(): void
    {
        $result = $this->subject->unhideNode('node-id');

        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(UntagSubtree::class, $this->handledCommands[0]);
        self::assertSame('node-id', $result['nodeAggregateId']);
        self::assertTrue($result['success']);
    }

    /**
     * @test
     */
    public function findAndReplaceDryRunDoesNotCallHandle(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $matchingNode = $this->createStubNodeWithProperties(
            'matching-node',
            'Vendor:Content.Text',
            ['title' => 'Old Title'],
        );

        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$matchingNode]));

        $this->handledCommands = [];

        $result = $this->subject->findAndReplace(
            'Old',
            'New',
            nodeTypeName: 'Vendor:Content.Text',
            propertyName: 'title',
            dryRun: true,
        );

        self::assertSame(1, $result['affectedNodes']);
        self::assertTrue($result['dryRun']);
        self::assertCount(0, $this->handledCommands);
        self::assertSame('matching-node', $result['matches'][0]['nodeAggregateId']);
        self::assertSame('Vendor:Content.Text', $result['matches'][0]['nodeTypeName']);
        self::assertSame('title', $result['matches'][0]['propertyName']);
        self::assertSame('Old Title', $result['matches'][0]['oldValue']);
        self::assertSame('New Title', $result['matches'][0]['newValue']);
    }

    /**
     * @test
     */
    public function findAndReplaceAppliesReplacements(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $node1 = $this->createStubNodeWithProperties('node-1', 'Vendor:Content.Text', ['text' => 'Hello World']);
        $node2 = $this->createStubNodeWithProperties('node-2', 'Vendor:Content.Text', ['text' => 'No match']);

        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node1, $node2]));

        $this->handledCommands = [];

        $result = $this->subject->findAndReplace(
            'Hello',
            'Hi',
            nodeTypeName: 'Vendor:Content.Text',
            propertyName: 'text',
        );

        self::assertSame(1, $result['affectedNodes']);
        self::assertFalse($result['dryRun']);
        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(SetNodeProperties::class, $this->handledCommands[0]);
    }

    /**
     * @test
     */
    public function findAndReplaceWithoutNodeTypeNameSearchesAllTypes(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $node1 = $this->createStubNodeWithProperties('node-1', 'Vendor:Content.Text', ['text' => 'Hello World']);
        $node2 = $this->createStubNodeWithProperties('node-2', 'Vendor:Content.Headline', ['title' => 'Hello There']);

        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node1, $node2]));

        $this->handledCommands = [];

        $result = $this->subject->findAndReplace(
            'Hello',
            'Hi',
            propertyName: 'text',
            dryRun: true,
        );

        // Only node-1 matches because node-2's matching property is "title", not "text"
        self::assertSame(1, $result['affectedNodes']);
        self::assertSame('Vendor:Content.Text', $result['matches'][0]['nodeTypeName']);

        // Now search without propertyName filter — both nodes match
        $result = $this->subject->findAndReplace(
            'Hello',
            'Hi',
            dryRun: true,
        );

        self::assertSame(2, $result['affectedNodes']);
        self::assertSame('Vendor:Content.Text', $result['matches'][0]['nodeTypeName']);
        self::assertSame('Vendor:Content.Headline', $result['matches'][1]['nodeTypeName']);
    }

    /**
     * @test
     */
    public function findAndReplaceWithoutPropertyNameSearchesAllStringProperties(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $node = $this->createStubNodeWithProperties('node-1', 'Vendor:Content.Text', [
            'title' => 'Hello Title',
            'text' => 'Hello Body',
        ]);

        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node]));

        $this->handledCommands = [];

        $result = $this->subject->findAndReplace(
            'Hello',
            'Hi',
            dryRun: true,
        );

        self::assertSame(2, $result['affectedNodes']);
        self::assertSame('title', $result['matches'][0]['propertyName']);
        self::assertSame('Hi Title', $result['matches'][0]['newValue']);
        self::assertSame('text', $result['matches'][1]['propertyName']);
        self::assertSame('Hi Body', $result['matches'][1]['newValue']);
    }

    /**
     * @test
     */
    public function findAndReplaceWithoutPropertyNameSkipsNonStringProperties(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $node = $this->createStubNodeWithMixedProperties('node-1', 'Vendor:Content.Text', [
            'title' => 'Hello World',
            'sortOrder' => 42,
        ]);

        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node]));

        $this->handledCommands = [];

        $result = $this->subject->findAndReplace(
            'Hello',
            'Hi',
            dryRun: true,
        );

        self::assertSame(1, $result['affectedNodes']);
        self::assertSame('title', $result['matches'][0]['propertyName']);
    }

    /**
     * @test
     */
    public function findAndReplaceWithBothFiltersOmitted(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $node1 = $this->createStubNodeWithProperties('node-1', 'Vendor:Content.Text', ['text' => 'Foo bar']);
        $node2 = $this->createStubNodeWithProperties('node-2', 'Vendor:Document.Page', ['title' => 'Foo page']);

        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node1, $node2]));

        $this->handledCommands = [];

        $result = $this->subject->findAndReplace('Foo', 'Baz');

        self::assertSame(2, $result['affectedNodes']);
        self::assertFalse($result['dryRun']);
        self::assertCount(2, $this->handledCommands);
        self::assertSame('Baz bar', $result['matches'][0]['newValue']);
        self::assertSame('Baz page', $result['matches'][1]['newValue']);
    }

    /**
     * @test
     */
    public function findAndReplaceTruncatesLongValues(): void
    {
        $service = new NodeWriteService(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
            propertyTruncateLength: 30,
        );

        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $longValue = str_repeat('A', 200) . 'Pellets' . str_repeat('B', 200);

        $node = $this->createStubNodeWithProperties('node-1', 'Vendor:Content.Text', ['text' => $longValue]);
        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node]));

        $result = $service->findAndReplace('Pellets', 'Holzpellets', dryRun: true);

        self::assertSame(1, $result['affectedNodes']);
        $match = $result['matches'][0];

        // Both old and new values are truncated to 30 + "…"
        self::assertSame(31, mb_strlen($match['oldValue']));
        self::assertStringEndsWith('…', $match['oldValue']);
        self::assertSame(31, mb_strlen($match['newValue']));
        self::assertStringEndsWith('…', $match['newValue']);
    }

    /**
     * @test
     */
    public function findAndReplaceDoesNotTruncateWithoutSetting(): void
    {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $this->contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $longValue = str_repeat('A', 300);

        $node = $this->createStubNodeWithProperties('node-1', 'Vendor:Content.Text', ['text' => $longValue]);
        $subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node]));

        // Default subject has no truncation configured
        $result = $this->subject->findAndReplace('A', 'B', dryRun: true);

        self::assertSame(300, mb_strlen($result['matches'][0]['oldValue']));
        self::assertSame(300, mb_strlen($result['matches'][0]['newValue']));
    }

    private function createStubNode(string $aggregateId, string $nodeTypeName): Node
    {
        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::fromString('test-workspace'),
            DimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateId::fromString($aggregateId),
            OriginDimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString($nodeTypeName),
            new PropertyCollection(
                SerializedPropertyValues::createEmpty(),
                $this->propertyConverter,
            ),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty(),
        );
    }

    /**
     * @param array<string, string> $propertyValues
     */
    private function createStubNodeWithProperties(
        string $aggregateId,
        string $nodeTypeName,
        array $propertyValues,
    ): Node {
        $serializedValues = [];
        foreach ($propertyValues as $name => $value) {
            $serializedValues[$name] = SerializedPropertyValue::create($value, 'string');
        }

        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::fromString('test-workspace'),
            DimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateId::fromString($aggregateId),
            OriginDimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString($nodeTypeName),
            new PropertyCollection(
                SerializedPropertyValues::fromArray($serializedValues),
                $this->propertyConverter,
            ),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty(),
        );
    }

    /**
     * @param array<string, string|int> $propertyValues
     */
    private function createStubNodeWithMixedProperties(
        string $aggregateId,
        string $nodeTypeName,
        array $propertyValues,
    ): Node {
        $serializedValues = [];
        foreach ($propertyValues as $name => $value) {
            $type = is_string($value) ? 'string' : 'integer';
            $serializedValues[$name] = SerializedPropertyValue::create($value, $type);
        }

        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::fromString('test-workspace'),
            DimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateId::fromString($aggregateId),
            OriginDimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString($nodeTypeName),
            new PropertyCollection(
                SerializedPropertyValues::fromArray($serializedValues),
                $this->propertyConverter,
            ),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty(),
        );
    }
}
