<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Dto\FindNodesRequest;
use GesagtGetan\NeosMcp\Service\NodeReadService;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Reference;
use Neos\ContentRepository\Core\Projection\ContentGraph\References;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspaces;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;

class NodeReadServiceTest extends TestCase
{
    private NodeReadService $subject;
    private ContentRepositoryFacade&Stub $contentRepository;
    private ContentGraphInterface&Stub $contentGraph;
    private ContentSubgraphInterface&Stub $subgraph;
    private PropertyConverter $propertyConverter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subgraph = self::createStub(ContentSubgraphInterface::class);
        $this->contentGraph = self::createStub(ContentGraphInterface::class);
        $this->contentGraph->method('getSubgraph')->willReturn($this->subgraph);
        $this->contentRepository = $this->createContentRepository($this->contentGraph);

        $serializer = self::createStub(Serializer::class);
        $serializer->method('denormalize')->willReturnCallback(
            static function (mixed $data, string $type): mixed {
                if ($type === \DateTimeImmutable::class && is_string($data)) {
                    return new \DateTimeImmutable($data);
                }
                if ($type === 'JsonSerializableObject') {
                    return new class ($data) implements \JsonSerializable {
                        public function __construct(private readonly mixed $data)
                        {
                        }

                        public function jsonSerialize(): mixed
                        {
                            return $this->data;
                        }
                    };
                }
                if ($type === 'StringableObject' && is_string($data)) {
                    return new class ($data) implements \Stringable {
                        public function __construct(private readonly string $value)
                        {
                        }

                        public function __toString(): string
                        {
                            return $this->value;
                        }
                    };
                }
                if ($type === 'PlainObject') {
                    return new \stdClass();
                }
                if ($type === 'DateTimeArray' && is_array($data)) {
                    $result = [];
                    foreach ($data as $d) {
                        \assert(\is_string($d));
                        $result[] = new \DateTimeImmutable($d);
                    }

                    return $result;
                }

                return $data;
            },
        );
        $this->propertyConverter = new PropertyConverter($serializer);

        $this->subject = $this->createSubject($this->contentRepository);
    }

    /**
     * Builds a facade stub that serves the given graph and a single "de" dimension.
     * Tests that need call expectations on the graph or subgraph wire their own mock
     * through here instead of using the shared stubs from setUp().
     */
    private function createContentRepository(ContentGraphInterface $contentGraph): ContentRepositoryFacade&Stub
    {
        $contentRepository = self::createStub(ContentRepositoryFacade::class);
        $contentRepository->method('getContentGraph')->willReturn($contentGraph);
        $contentRepository->method('getDimensionSpacePoints')
            ->willReturn(new DimensionSpacePointSet([DimensionSpacePoint::fromArray(['language' => 'de'])]));

        return $contentRepository;
    }

    private function createSubject(ContentRepositoryFacade $contentRepository): NodeReadService
    {
        return new NodeReadService($contentRepository, WorkspaceName::fromString('test-workspace'));
    }

    private function createSubjectWithContentGraph(ContentGraphInterface $contentGraph): NodeReadService
    {
        return $this->createSubject($this->createContentRepository($contentGraph));
    }

    private function createSubjectWithSubgraph(ContentSubgraphInterface $subgraph): NodeReadService
    {
        $contentGraph = self::createStub(ContentGraphInterface::class);
        $contentGraph->method('getSubgraph')->willReturn($subgraph);

        return $this->createSubjectWithContentGraph($contentGraph);
    }

    #[Test]
    public function getContentRepositoryInfoReturnsDimensionsAndWorkspaces(): void
    {
        $dimensionSource = self::createStub(ContentDimensionSourceInterface::class);
        $dimensionSource->method('getContentDimensionsOrderedByPriority')->willReturn([]);
        $this->contentRepository->method('getContentDimensionSource')->willReturn($dimensionSource);

        $this->contentRepository->method('findWorkspaces')->willReturn(
            Workspaces::fromArray([
                Workspace::create(
                    WorkspaceName::fromString('live'),
                    null,
                    ContentStreamId::fromString('cs-1'),
                    WorkspaceStatus::UP_TO_DATE,
                    false,
                ),
            ]),
        );

        $contentRepositoryId = ContentRepositoryId::fromString('default');
        $this->contentRepository->method('getId')->willReturn($contentRepositoryId);

        $result = $this->subject->getContentRepositoryInfo();

        self::assertSame('default', $result->contentRepositoryId);
        self::assertCount(1, $result->workspaces);
        $workspaces = iterator_to_array($result->workspaces);
        self::assertSame('live', $workspaces[0]->name);
    }

    #[Test]
    public function getNodeReturnsNullForMissingNode(): void
    {
        $this->subgraph->method('findNodeById')->willReturn(null);

        $result = $this->subject->getNode('non-existent-id');

        self::assertNull($result);
    }

    #[Test]
    public function getNodeReturnsSerializedNode(): void
    {
        $node = $this->createStubNode(
            'test-aggregate-id',
            'Vendor:Document.Page',
            'my-page',
        );

        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('test-aggregate-id');

        self::assertNotNull($result);
        self::assertSame('test-aggregate-id', $result->nodeAggregateId);
        self::assertSame('Vendor:Document.Page', $result->nodeTypeName);
        self::assertSame('my-page', $result->nodeName);
    }

    #[Test]
    public function findNodesReturnsEmptyCollectionWhenNoSitesRoot(): void
    {
        $this->subgraph->method('findRootNodeByType')->willReturn(null);

        $result = $this->subject->findNodes(self::defaultFindNodesRequest());

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function getChildrenDelegatesToFindChildNodes(): void
    {
        $node = $this->createStubNode('child-1', 'Vendor:Content.Text');

        $this->subgraph->method('findChildNodes')->willReturn(
            Nodes::fromArray([$node]),
        );

        $result = $this->subject->getChildren('parent-id');

        self::assertCount(1, $result);
        $children = iterator_to_array($result);
        self::assertSame('child-1', $children[0]->nodeAggregateId);
    }

    // ── Property Serialization Tests ────────────────────────────────

    #[Test]
    public function getNodeSerializesDateTimeAsAtomString(): void
    {
        $node = $this->createStubNodeWithTypedProperty(
            'dt-node',
            'createdAt',
            '2024-01-15T10:30:00+00:00',
            \DateTimeImmutable::class,
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('dt-node');

        self::assertNotNull($result);
        self::assertSame('2024-01-15T10:30:00+00:00', $result->properties['createdAt']);
    }

    #[Test]
    public function getNodeSerializesJsonSerializableAsJsonValue(): void
    {
        $node = $this->createStubNodeWithTypedProperty(
            'json-node',
            'metadata',
            ['key' => 'value', 'count' => 42],
            'JsonSerializableObject',
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('json-node');

        self::assertNotNull($result);
        self::assertSame(['key' => 'value', 'count' => 42], $result->properties['metadata']);
    }

    #[Test]
    public function getNodeSerializesStringableAsString(): void
    {
        $node = $this->createStubNodeWithTypedProperty(
            'str-node',
            'uri',
            'https://example.com',
            'StringableObject',
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('str-node');

        self::assertNotNull($result);
        self::assertSame('https://example.com', $result->properties['uri']);
    }

    #[Test]
    public function getNodeSerializesPlainObjectAsClassName(): void
    {
        $node = $this->createStubNodeWithTypedProperty(
            'obj-node',
            'unknown',
            'ignored',
            'PlainObject',
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('obj-node');

        self::assertNotNull($result);
        self::assertSame('[object:stdClass]', $result->properties['unknown']);
    }

    #[Test]
    public function getNodeSerializesNestedArrayWithDateTimesRecursively(): void
    {
        $node = $this->createStubNodeWithTypedProperty(
            'arr-node',
            'dates',
            ['2024-01-15T10:00:00+00:00', '2024-06-30T18:00:00+00:00'],
            'DateTimeArray',
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('arr-node');

        self::assertNotNull($result);
        self::assertIsArray($result->properties['dates']);
        self::assertSame('2024-01-15T10:00:00+00:00', $result->properties['dates'][0]);
        self::assertSame('2024-06-30T18:00:00+00:00', $result->properties['dates'][1]);
    }

    // ── Visibility Constraints Tests ────────────────────────────────

    #[Test]
    public function getSubgraphUsesExcludeRemovedConstraintsByDefault(): void
    {
        $this->subgraph->method('findNodeById')->willReturn(null);
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $contentGraph->expects(self::once())
            ->method('getSubgraph')
            ->with(self::anything(), self::equalTo(NeosVisibilityConstraints::excludeRemoved()))
            ->willReturn($this->subgraph);
        $subject = $this->createSubjectWithContentGraph($contentGraph);

        $subject->getNode('any-id');
    }

    #[Test]
    public function getSubgraphUsesEmptyConstraintsWhenIncludeRemovedIsTrue(): void
    {
        $this->subgraph->method('findNodeById')->willReturn(null);
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $contentGraph->expects(self::once())
            ->method('getSubgraph')
            ->with(self::anything(), self::equalTo(VisibilityConstraints::createEmpty()))
            ->willReturn($this->subgraph);
        $subject = $this->createSubjectWithContentGraph($contentGraph);

        $subject->getNode('any-id', includeRemoved: true);
    }

    // ── Hidden Field Tests ──────────────────────────────────────────

    #[Test]
    public function getNodeReturnsFalseHiddenForVisibleNode(): void
    {
        $node = $this->createStubNode('visible-node', 'Vendor:Document.Page');
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('visible-node');

        self::assertNotNull($result);
        self::assertFalse($result->hidden);
    }

    #[Test]
    public function getNodeReturnsTrueHiddenForDisabledNode(): void
    {
        $node = $this->createStubNodeWithTags(
            'hidden-node',
            'Vendor:Document.Page',
            NodeTags::create(SubtreeTags::fromStrings('disabled'), SubtreeTags::createEmpty()),
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('hidden-node');

        self::assertNotNull($result);
        self::assertTrue($result->hidden);
    }

    #[Test]
    public function getNodeReturnsTrueHiddenForInheritedDisabledNode(): void
    {
        $node = $this->createStubNodeWithTags(
            'inherited-hidden-node',
            'Vendor:Document.Page',
            NodeTags::create(SubtreeTags::createEmpty(), SubtreeTags::fromStrings('disabled')),
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('inherited-hidden-node');

        self::assertNotNull($result);
        self::assertTrue($result->hidden);
    }

    // ── Property Truncation Tests ───────────────────────────────────

    #[Test]
    public function findNodesTruncatesLongStringProperties(): void
    {
        $service = new NodeReadService(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
            propertyTruncateLength: 30,
        );

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $this->subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $node = $this->createStubNodeWithTypedProperty(
            'long-node',
            'text',
            str_repeat('A', 100),
            'string',
        );
        $this->subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node]));

        $result = $service->findNodes(self::defaultFindNodesRequest());

        self::assertCount(1, $result);
        $nodes = iterator_to_array($result);
        $text = $nodes[0]->properties['text'];
        self::assertIsString($text);
        self::assertSame(31, mb_strlen($text)); // 30 + "…"
        self::assertStringEndsWith('…', $text);
    }

    #[Test]
    public function findNodesDoesNotTruncateShortStrings(): void
    {
        $service = new NodeReadService(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
            propertyTruncateLength: 30,
        );

        $sitesRoot = $this->createStubNode('sites-root', 'Neos.Neos:Sites');
        $this->subgraph->method('findRootNodeByType')->willReturn($sitesRoot);

        $node = $this->createStubNodeWithTypedProperty(
            'short-node',
            'title',
            'Short title',
            'string',
        );
        $this->subgraph->method('findDescendantNodes')->willReturn(Nodes::fromArray([$node]));

        $result = $service->findNodes(self::defaultFindNodesRequest());

        self::assertCount(1, $result);
        $nodes = iterator_to_array($result);
        self::assertSame('Short title', $nodes[0]->properties['title']);
    }

    #[Test]
    public function getNodeReturnsFullPropertyValues(): void
    {
        $longValue = str_repeat('A', 100);
        $node = $this->createStubNodeWithTypedProperty(
            'full-node',
            'text',
            $longValue,
            'string',
        );
        $this->subgraph->method('findNodeById')->willReturn($node);

        // Even with truncation configured, getNode returns full values
        $service = new NodeReadService(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
            propertyTruncateLength: 30,
        );

        $result = $service->getNode('full-node');

        self::assertNotNull($result);
        self::assertSame($longValue, $result->properties['text']);
    }

    #[Test]
    public function getChildrenTruncatesLongStringProperties(): void
    {
        $service = new NodeReadService(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
            propertyTruncateLength: 30,
        );

        $node = $this->createStubNodeWithTypedProperty(
            'child-node',
            'text',
            str_repeat('B', 100),
            'string',
        );
        $this->subgraph->method('findChildNodes')->willReturn(Nodes::fromArray([$node]));

        $result = $service->getChildren('parent-id');

        self::assertCount(1, $result);
        $children = iterator_to_array($result);
        $text = $children[0]->properties['text'];
        self::assertIsString($text);
        self::assertSame(31, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    // ── Reference Tests ─────────────────────────────────────────────

    #[Test]
    public function findReferencesReturnsSerializedReferences(): void
    {
        $target = $this->createStubNode('target-id', 'Vendor:Document.Page', 'target-page');
        $references = References::fromArray([
            new Reference($target, ReferenceName::fromString('authors'), null),
        ]);
        $this->subgraph->method('findReferences')->willReturn($references);

        $result = $this->subject->findReferences('source-id');

        self::assertCount(1, $result);
        $items = iterator_to_array($result);
        self::assertSame('authors', $items[0]->referenceName);
        self::assertSame('target-id', $items[0]->target->nodeAggregateId);
        self::assertSame([], $items[0]->properties);
    }

    #[Test]
    public function findReferencesSerializesEdgeProperties(): void
    {
        $target = $this->createStubNode('target-id', 'Vendor:Asset');
        $edgeProperties = new PropertyCollection(
            SerializedPropertyValues::fromArray([
                'caption' => SerializedPropertyValue::create('Cover', 'string'),
            ]),
            $this->propertyConverter,
        );
        $references = References::fromArray([
            new Reference($target, ReferenceName::fromString('highlight'), $edgeProperties),
        ]);
        $this->subgraph->method('findReferences')->willReturn($references);

        $result = $this->subject->findReferences('source-id');

        $items = iterator_to_array($result);
        self::assertSame(['caption' => 'Cover'], $items[0]->properties);
    }

    #[Test]
    public function findReferencesAppliesReferenceNameFilter(): void
    {
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $subgraph->expects(self::once())
            ->method('findReferences')
            ->with(
                self::callback(static fn (mixed $id): bool => $id instanceof NodeAggregateId && $id->value === 'source-id'),
                self::callback(static fn (mixed $filter): bool => $filter instanceof FindReferencesFilter
                    && $filter->referenceName instanceof ReferenceName
                    && $filter->referenceName->value === 'authors'),
            )
            ->willReturn(References::fromArray([]));
        $subject = $this->createSubjectWithSubgraph($subgraph);

        $subject->findReferences('source-id', 'authors');
    }

    #[Test]
    public function findBackReferencesReturnsSerializedReferences(): void
    {
        $source = $this->createStubNode('source-id', 'Vendor:Document.Page');
        $references = References::fromArray([
            new Reference($source, ReferenceName::fromString('relatedTo'), null),
        ]);
        $this->subgraph->method('findBackReferences')->willReturn($references);

        $result = $this->subject->findBackReferences('target-id');

        self::assertCount(1, $result);
        $items = iterator_to_array($result);
        self::assertSame('relatedTo', $items[0]->referenceName);
        self::assertSame('source-id', $items[0]->target->nodeAggregateId);
    }

    #[Test]
    public function findBackReferencesAppliesReferenceNameFilter(): void
    {
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $subgraph->expects(self::once())
            ->method('findBackReferences')
            ->with(
                self::callback(static fn (mixed $id): bool => $id instanceof NodeAggregateId && $id->value === 'target-id'),
                self::callback(static fn (mixed $filter): bool => $filter instanceof FindBackReferencesFilter
                    && $filter->referenceName instanceof ReferenceName
                    && $filter->referenceName->value === 'relatedTo'),
            )
            ->willReturn(References::fromArray([]));
        $subject = $this->createSubjectWithSubgraph($subgraph);

        $subject->findBackReferences('target-id', 'relatedTo');
    }

    // ── Stub Helpers ────────────────────────────────────────────────

    private static function defaultFindNodesRequest(): FindNodesRequest
    {
        return new FindNodesRequest(
            nodeTypeName: null,
            searchTerm: null,
            parentNodeAggregateId: null,
            limit: 100,
            dimensionSpacePoint: null,
            includeRemoved: false,
        );
    }

    private function createStubNode(
        string $aggregateId,
        string $nodeTypeName,
        ?string $nodeName = null,
    ): Node {
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
            $nodeName !== null ? NodeName::fromString($nodeName) : null,
            NodeTags::createEmpty(),
            Timestamps::create(
                new \DateTimeImmutable(),
                new \DateTimeImmutable(),
                null,
                null,
            ),
            VisibilityConstraints::createEmpty(),
        );
    }

    private function createStubNodeWithTags(
        string $aggregateId,
        string $nodeTypeName,
        NodeTags $tags,
    ): Node {
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
            $tags,
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty(),
        );
    }

    /**
     * @param int|float|string|bool|array<mixed>|\ArrayObject<int|string, mixed> $serializedValue
     */
    private function createStubNodeWithTypedProperty(
        string $aggregateId,
        string $propertyName,
        int|float|string|bool|array|\ArrayObject $serializedValue,
        string $type,
    ): Node {
        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::fromString('test-workspace'),
            DimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateId::fromString($aggregateId),
            OriginDimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString('Vendor:Test'),
            new PropertyCollection(
                SerializedPropertyValues::fromArray([
                    $propertyName => SerializedPropertyValue::create($serializedValue, $type),
                ]),
                $this->propertyConverter,
            ),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty(),
        );
    }
}
