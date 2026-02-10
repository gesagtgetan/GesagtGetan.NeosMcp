<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Service;

use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Tests\Functional\AbstractFunctionalTest;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

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

    /**
     * @test
     */
    public function getContentRepositoryInfoReturnsValidStructure(): void
    {
        $result = $this->nodeReadService->getContentRepositoryInfo();

        self::assertArrayHasKey('contentRepositoryId', $result);
        self::assertSame('default', $result['contentRepositoryId']);
        self::assertArrayHasKey('dimensions', $result);
        self::assertArrayHasKey('workspaces', $result);
        self::assertArrayHasKey('dimensionSpacePoints', $result);
        self::assertIsArray($result['workspaces']);
        self::assertNotEmpty($result['workspaces']);
    }

    /**
     * @test
     */
    public function findNodesReturnsCreatedNode(): void
    {
        $this->createTestDocument('test-doc-1', 'Test Page');

        $result = $this->nodeReadService->findNodes(
            searchTerm: 'Test Page',
        );

        self::assertCount(1, $result);
        self::assertSame('test-doc-1', $result[0]['nodeAggregateId']);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $result[0]['nodeTypeName']);
        self::assertSame('Test Page', $result[0]['properties']['title']);
    }

    /**
     * @test
     */
    public function findNodesFiltersByNodeType(): void
    {
        $this->createTestDocument('doc-1', 'Page One');

        $result = $this->nodeReadService->findNodes(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
        );

        self::assertCount(1, $result);
        self::assertSame('doc-1', $result[0]['nodeAggregateId']);
    }

    /**
     * @test
     */
    public function getNodeReturnsNodeById(): void
    {
        $this->createTestDocument('my-doc', 'My Document', 'Some text content');

        $result = $this->nodeReadService->getNode('my-doc');

        self::assertNotNull($result);
        self::assertSame('my-doc', $result['nodeAggregateId']);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $result['nodeTypeName']);
        self::assertSame('My Document', $result['properties']['title']);
        self::assertSame('Some text content', $result['properties']['text']);
    }

    /**
     * @test
     */
    public function getNodeReturnsNullForNonExistentNode(): void
    {
        $result = $this->nodeReadService->getNode('does-not-exist');

        self::assertNull($result);
    }

    /**
     * @test
     */
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
        $ids = array_column($result, 'nodeAggregateId');
        self::assertContains('child-1', $ids);
        self::assertContains('child-2', $ids);
    }

    /**
     * @test
     */
    public function getNodeReadsContentTextWithinDocumentCollection(): void
    {
        $this->createTestDocument('doc-with-content', 'Document with content');

        // Find the tethered "main" ContentCollection.
        $children = $this->nodeReadService->getChildren('doc-with-content');
        $mainCollection = null;
        foreach ($children as $child) {
            if ($child['nodeTypeName'] === 'Neos.Neos:ContentCollection') {
                $mainCollection = $child;
                break;
            }
        }
        self::assertNotNull($mainCollection, 'Document must have a tethered ContentCollection');

        // Create a text content node inside the collection.
        $this->createTestContentText('text-1', 'Hello from the content area', $mainCollection['nodeAggregateId']);

        // Read the content node back via getNode.
        $node = $this->nodeReadService->getNode('text-1');

        self::assertNotNull($node);
        self::assertSame('text-1', $node['nodeAggregateId']);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Content.Text', $node['nodeTypeName']);
        self::assertSame('Hello from the content area', $node['properties']['text']);

        // Verify it appears in findNodes filtered by content type.
        $found = $this->nodeReadService->findNodes(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Content.Text',
        );
        self::assertCount(1, $found);
        self::assertSame('text-1', $found[0]['nodeAggregateId']);
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
