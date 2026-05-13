<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
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
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspaces;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Serializer\Serializer;

class NodeReadServiceTest extends UnitTestCase
{
    private NodeReadService $subject;
    private ContentRepositoryFacade&MockObject $contentRepository;
    private ContentGraphInterface&MockObject $contentGraph;
    private ContentSubgraphInterface&MockObject $subgraph;
    private PropertyConverter $propertyConverter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentRepository = $this->createMock(ContentRepositoryFacade::class);
        $this->contentGraph = $this->createMock(ContentGraphInterface::class);
        $this->subgraph = $this->createMock(ContentSubgraphInterface::class);

        $this->contentRepository->method('getContentGraph')->willReturn($this->contentGraph);
        $this->contentGraph->method('getSubgraph')->willReturn($this->subgraph);

        $dsp = DimensionSpacePoint::fromArray(['language' => 'de']);
        $this->contentRepository->method('getDimensionSpacePoints')
            ->willReturn(new DimensionSpacePointSet([$dsp]));

        $serializer = $this->getMockBuilder(Serializer::class)
            ->disableOriginalConstructor()
            ->getMock();
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

        $this->subject = new NodeReadService(
            $this->contentRepository,
            WorkspaceName::fromString('test-workspace'),
        );
    }

    #[Test]
    public function getContentRepositoryInfoReturnsDimensionsAndWorkspaces(): void
    {
        $dimensionSource = $this->createMock(ContentDimensionSourceInterface::class);
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

        self::assertArrayHasKey('contentRepositoryId', $result);
        self::assertSame('default', $result['contentRepositoryId']);
        self::assertArrayHasKey('dimensions', $result);
        self::assertArrayHasKey('workspaces', $result);
        self::assertArrayHasKey('dimensionSpacePoints', $result);
        self::assertCount(1, $result['workspaces']);
        self::assertSame('live', $result['workspaces'][0]['name']);
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
        self::assertSame('test-aggregate-id', $result['nodeAggregateId']);
        self::assertSame('Vendor:Document.Page', $result['nodeTypeName']);
        self::assertSame('my-page', $result['nodeName']);
        self::assertArrayHasKey('properties', $result);
    }

    #[Test]
    public function findNodesReturnsEmptyArrayWhenNoSitesRoot(): void
    {
        $this->subgraph->method('findRootNodeByType')->willReturn(null);

        $result = $this->subject->findNodes();

        self::assertSame([], $result);
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
        self::assertSame('child-1', $result[0]['nodeAggregateId']);
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
        self::assertSame('2024-01-15T10:30:00+00:00', $result['properties']['createdAt']);
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
        self::assertSame(['key' => 'value', 'count' => 42], $result['properties']['metadata']);
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
        self::assertSame('https://example.com', $result['properties']['uri']);
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
        self::assertSame('[object:stdClass]', $result['properties']['unknown']);
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
        self::assertIsArray($result['properties']['dates']);
        self::assertSame('2024-01-15T10:00:00+00:00', $result['properties']['dates'][0]);
        self::assertSame('2024-06-30T18:00:00+00:00', $result['properties']['dates'][1]);
    }

    // ── Visibility Constraints Tests ────────────────────────────────

    #[Test]
    public function getSubgraphUsesExcludeRemovedConstraintsByDefault(): void
    {
        $this->contentGraph->expects(self::once())
            ->method('getSubgraph')
            ->with(self::anything(), self::equalTo(NeosVisibilityConstraints::excludeRemoved()));

        $this->subgraph->method('findNodeById')->willReturn(null);

        $this->subject->getNode('any-id');
    }

    #[Test]
    public function getSubgraphUsesEmptyConstraintsWhenIncludeRemovedIsTrue(): void
    {
        $this->contentGraph->expects(self::once())
            ->method('getSubgraph')
            ->with(self::anything(), self::equalTo(VisibilityConstraints::createEmpty()));

        $this->subgraph->method('findNodeById')->willReturn(null);

        $this->subject->getNode('any-id', includeRemoved: true);
    }

    // ── Hidden Field Tests ──────────────────────────────────────────

    #[Test]
    public function getNodeReturnsFalseHiddenForVisibleNode(): void
    {
        $node = $this->createStubNode('visible-node', 'Vendor:Document.Page');
        $this->subgraph->method('findNodeById')->willReturn($node);

        $result = $this->subject->getNode('visible-node');

        self::assertNotNull($result);
        self::assertFalse($result['hidden']);
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
        self::assertTrue($result['hidden']);
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
        self::assertTrue($result['hidden']);
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

        $result = $service->findNodes();

        self::assertCount(1, $result);
        $text = $result[0]['properties']['text'];
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

        $result = $service->findNodes();

        self::assertCount(1, $result);
        self::assertSame('Short title', $result[0]['properties']['title']);
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
        self::assertSame($longValue, $result['properties']['text']);
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
        $text = $result[0]['properties']['text'];
        self::assertIsString($text);
        self::assertSame(31, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    // ── Stub Helpers ────────────────────────────────────────────────

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
