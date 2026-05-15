<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Service;

use GesagtGetan\NeosMcp\Dto\FindNodesRequest;
use GesagtGetan\NeosMcp\Dto\NodeInfo;
use GesagtGetan\NeosMcp\Dto\NodeInfoCollection;
use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Tests\Functional\AbstractFunctionalTest;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use PHPUnit\Framework\Attributes\Test;

class NodeReadServiceTest extends AbstractFunctionalTest
{
    private NodeReadService $nodeReadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodeReadService = new NodeReadService(
            $this->facade,
            WorkspaceName::forLive(),
        );
    }

    #[Test]
    public function getContentRepositoryInfoReturnsValidStructure(): void
    {
        $result = $this->nodeReadService->getContentRepositoryInfo();

        self::assertSame('default', $result->contentRepositoryId);
        self::assertFalse($result->workspaces->isEmpty());
    }

    #[Test]
    public function findNodesReturnsCreatedNode(): void
    {
        $this->createTestDocument('test-doc-1', 'Test Page');

        $result = $this->nodeReadService->findNodes(self::buildFindNodesRequest(
            searchTerm: 'Test Page',
        ));

        self::assertCount(1, $result);
        $nodes = iterator_to_array($result);
        self::assertSame('test-doc-1', $nodes[0]->nodeAggregateId);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $nodes[0]->nodeTypeName);
        self::assertSame('Test Page', $nodes[0]->properties['title']);
    }

    #[Test]
    public function findNodesFiltersByNodeType(): void
    {
        $this->createTestDocument('doc-1', 'Page One');

        $result = $this->nodeReadService->findNodes(self::buildFindNodesRequest(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
        ));

        self::assertCount(1, $result);
        $nodes = iterator_to_array($result);
        self::assertSame('doc-1', $nodes[0]->nodeAggregateId);
    }

    #[Test]
    public function getNodeReturnsNodeById(): void
    {
        $this->createTestDocument('my-doc', 'My Document', 'Some text content');

        $result = $this->nodeReadService->getNode('my-doc');

        self::assertNotNull($result);
        self::assertSame('my-doc', $result->nodeAggregateId);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $result->nodeTypeName);
        self::assertSame('My Document', $result->properties['title']);
        self::assertSame('Some text content', $result->properties['text']);
    }

    #[Test]
    public function getNodeReturnsNullForNonExistentNode(): void
    {
        $result = $this->nodeReadService->getNode('does-not-exist');

        self::assertNull($result);
    }

    #[Test]
    public function getChildrenReturnsChildNodes(): void
    {
        $this->createTestDocument('parent-doc', 'Parent');
        $this->createTestDocument('child-1', 'Child One', parentId: 'parent-doc');
        $this->createTestDocument('child-2', 'Child Two', parentId: 'parent-doc');

        $result = $this->nodeReadService->getChildren(
            'parent-doc',
            'GesagtGetan.NeosMcp:Testing.Document',
        );

        self::assertCount(2, $result);
        $ids = self::collectAggregateIds($result);
        self::assertContains('child-1', $ids);
        self::assertContains('child-2', $ids);
    }

    #[Test]
    public function getNodeReadsContentTextWithinDocumentCollection(): void
    {
        $this->createTestDocument('doc-with-content', 'Document with content');

        // Find the tethered "main" ContentCollection.
        $children = $this->nodeReadService->getChildren('doc-with-content');
        $mainCollection = null;
        foreach ($children as $child) {
            if ($child->nodeTypeName === 'Neos.Neos:ContentCollection') {
                $mainCollection = $child;
                break;
            }
        }
        self::assertNotNull($mainCollection, 'Document must have a tethered ContentCollection');

        // Create a text content node inside the collection.
        $this->createTestContentText('text-1', 'Hello from the content area', $mainCollection->nodeAggregateId);

        // Read the content node back via getNode.
        $node = $this->nodeReadService->getNode('text-1');

        self::assertNotNull($node);
        self::assertSame('text-1', $node->nodeAggregateId);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Content.Text', $node->nodeTypeName);
        self::assertSame('Hello from the content area', $node->properties['text']);

        // Verify it appears in findNodes filtered by content type.
        $found = $this->nodeReadService->findNodes(self::buildFindNodesRequest(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Content.Text',
        ));
        self::assertCount(1, $found);
        $foundNodes = iterator_to_array($found);
        self::assertSame('text-1', $foundNodes[0]->nodeAggregateId);
    }

    // ── Soft-Removal (Trash) Visibility Tests ─────────────────────

    #[Test]
    public function findNodesExcludesSoftRemovedNodesByDefault(): void
    {
        $this->createTestDocument('visible-doc', 'Visible');
        $this->createTestDocument('removed-doc', 'Removed');
        $this->softRemoveNode('removed-doc');

        $result = $this->nodeReadService->findNodes(self::buildFindNodesRequest(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
        ));

        $ids = self::collectAggregateIds($result);
        self::assertContains('visible-doc', $ids);
        self::assertNotContains('removed-doc', $ids, 'Soft-removed node must not appear in default findNodes results');
    }

    #[Test]
    public function findNodesIncludesSoftRemovedNodesWhenRequested(): void
    {
        $this->createTestDocument('visible-doc', 'Visible');
        $this->createTestDocument('removed-doc', 'Removed');
        $this->softRemoveNode('removed-doc');

        $result = $this->nodeReadService->findNodes(self::buildFindNodesRequest(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
            includeRemoved: true,
        ));

        $ids = self::collectAggregateIds($result);
        self::assertContains('visible-doc', $ids);
        self::assertContains('removed-doc', $ids, 'Soft-removed node must appear when includeRemoved is true');
    }

    #[Test]
    public function getNodeReturnsNullForSoftRemovedNodeByDefault(): void
    {
        $this->createTestDocument('removed-doc', 'Removed');
        $this->softRemoveNode('removed-doc');

        self::assertNull(
            $this->nodeReadService->getNode('removed-doc'),
            'Soft-removed node must not be returned by default',
        );
    }

    #[Test]
    public function getNodeReturnsSoftRemovedNodeWhenRequested(): void
    {
        $this->createTestDocument('removed-doc', 'Removed');
        $this->softRemoveNode('removed-doc');

        $result = $this->nodeReadService->getNode('removed-doc', includeRemoved: true);

        self::assertNotNull($result, 'Soft-removed node must be returned when includeRemoved is true');
        self::assertSame('removed-doc', $result->nodeAggregateId);
    }

    #[Test]
    public function getChildrenExcludesSoftRemovedChildrenByDefault(): void
    {
        $this->createTestDocument('child-visible', 'Visible Child');
        $this->createTestDocument('child-removed', 'Removed Child');
        $this->softRemoveNode('child-removed');

        $result = $this->nodeReadService->getChildren(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
        );

        $ids = self::collectAggregateIds($result);
        self::assertContains('child-visible', $ids);
        self::assertNotContains('child-removed', $ids, 'Soft-removed child must not appear in default getChildren results');
    }

    #[Test]
    public function getChildrenIncludesSoftRemovedChildrenWhenRequested(): void
    {
        $this->createTestDocument('child-visible', 'Visible Child');
        $this->createTestDocument('child-removed', 'Removed Child');
        $this->softRemoveNode('child-removed');

        $result = $this->nodeReadService->getChildren(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            includeRemoved: true,
        );

        $ids = self::collectAggregateIds($result);
        self::assertContains('child-visible', $ids);
        self::assertContains('child-removed', $ids, 'Soft-removed child must appear when includeRemoved is true');
    }

    private static function buildFindNodesRequest(
        ?string $nodeTypeName = null,
        ?string $searchTerm = null,
        ?string $parentNodeAggregateId = null,
        int $limit = 100,
        bool $includeRemoved = false,
    ): FindNodesRequest {
        return new FindNodesRequest(
            nodeTypeName: $nodeTypeName,
            searchTerm: $searchTerm,
            parentNodeAggregateId: $parentNodeAggregateId,
            limit: $limit,
            dimensionSpacePoint: null,
            includeRemoved: $includeRemoved,
        );
    }

    /**
     * @return list<string>
     */
    private static function collectAggregateIds(NodeInfoCollection $collection): array
    {
        return array_map(static fn (NodeInfo $node) => $node->nodeAggregateId, iterator_to_array($collection, false));
    }

    private function softRemoveNode(string $nodeAggregateId): void
    {
        $dsp = $this->resolveDefaultDimensionSpacePoint();

        $this->contentRepository->handle(
            TagSubtree::create(
                WorkspaceName::forLive(),
                NodeAggregateId::fromString($nodeAggregateId),
                $dsp,
                NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
                NeosSubtreeTag::removed(),
            ),
        );
    }

    private function createTestContentText(
        string $nodeAggregateId,
        string $text,
        string $parentId,
    ): void {
        $dsp = $this->resolveDefaultDimensionSpacePoint();

        $command = CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            NodeAggregateId::fromString($nodeAggregateId),
            NodeTypeName::fromString('GesagtGetan.NeosMcp:Testing.Content.Text'),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($dsp),
            NodeAggregateId::fromString($parentId),
        )->withInitialPropertyValues(PropertyValuesToWrite::fromArray([
            'text' => $text,
        ]));

        $this->contentRepository->handle($command);
    }

    private function createTestDocument(
        string $nodeAggregateId,
        string $title,
        string $text = '',
        string $parentId = 'test-site',
    ): void {
        $dsp = $this->resolveDefaultDimensionSpacePoint();

        $command = CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            NodeAggregateId::fromString($nodeAggregateId),
            NodeTypeName::fromString('GesagtGetan.NeosMcp:Testing.Document'),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($dsp),
            NodeAggregateId::fromString($parentId),
        )->withInitialPropertyValues(PropertyValuesToWrite::fromArray([
            'title' => $title,
            'text' => $text,
        ]));

        $this->contentRepository->handle($command);
    }
}
