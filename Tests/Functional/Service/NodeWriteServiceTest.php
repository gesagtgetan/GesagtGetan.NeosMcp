<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Service;

use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use GesagtGetan\NeosMcp\Tests\Functional\AbstractFunctionalTest;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

class NodeWriteServiceTest extends AbstractFunctionalTest
{
    private NodeWriteService $nodeWriteService;
    private NodeReadService $nodeReadService;

    protected function setUp(): void
    {
        parent::setUp();

        $workspaceName = WorkspaceName::fromString('test-workspace');
        $this->createTestWorkspace($workspaceName);

        $this->nodeWriteService = new NodeWriteService($this->facade, $workspaceName);
        $this->nodeReadService = new NodeReadService($this->facade, $workspaceName);
    }

    /**
     * @test
     */
    public function createNodeAndRetrieve(): void
    {
        $result = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Created by test'],
        );

        self::assertTrue($result['success']);
        self::assertNotEmpty($result['nodeAggregateId']);

        $node = $this->nodeReadService->getNode($result['nodeAggregateId']);

        self::assertNotNull($node);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $node['nodeTypeName']);
        self::assertSame('Created by test', $node['properties']['title']);
    }

    /**
     * @test
     */
    public function setNodePropertiesUpdatesValues(): void
    {
        $createResult = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Original'],
        );

        $this->nodeWriteService->setNodeProperties(
            $createResult['nodeAggregateId'],
            ['title' => 'Updated'],
        );

        $node = $this->nodeReadService->getNode($createResult['nodeAggregateId']);

        self::assertNotNull($node);
        self::assertSame('Updated', $node['properties']['title']);
    }

    /**
     * @test
     */
    public function removeNodeRemovesFromGraph(): void
    {
        $createResult = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'To Remove'],
        );

        $this->nodeWriteService->removeNode($createResult['nodeAggregateId']);

        $node = $this->nodeReadService->getNode($createResult['nodeAggregateId']);
        self::assertNull($node);
    }

    /**
     * @test
     */
    public function moveNodeChangesParent(): void
    {
        $parent1 = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Parent 1'],
        );

        $parent2 = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Parent 2'],
        );

        $child = $this->nodeWriteService->createNode(
            $parent1['nodeAggregateId'],
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Child'],
        );

        $this->nodeWriteService->moveNode(
            $child['nodeAggregateId'],
            $parent2['nodeAggregateId'],
        );

        $parent1Children = $this->nodeReadService->getChildren(
            $parent1['nodeAggregateId'],
            'GesagtGetan.NeosMcp:Testing.Document',
        );
        self::assertCount(0, $parent1Children);

        $parent2Children = $this->nodeReadService->getChildren(
            $parent2['nodeAggregateId'],
            'GesagtGetan.NeosMcp:Testing.Document',
        );
        self::assertCount(1, $parent2Children);
        self::assertSame($child['nodeAggregateId'], $parent2Children[0]['nodeAggregateId']);
    }

    /**
     * @test
     */
    public function findAndReplacePropertyUpdatesMatchingNodes(): void
    {
        $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Hello World'],
        );

        $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'No Match Here'],
        );

        $result = $this->nodeWriteService->findAndReplaceProperty(
            'GesagtGetan.NeosMcp:Testing.Document',
            'title',
            'Hello',
            'Hi',
        );

        self::assertSame(1, $result['affectedNodes']);
        self::assertFalse($result['dryRun']);
        self::assertSame('Hello World', $result['matches'][0]['oldValue']);
        self::assertSame('Hi World', $result['matches'][0]['newValue']);
    }

    /**
     * @test
     */
    public function createMultipleContentNodesInDocumentCollection(): void
    {
        // Create a document — this auto-creates its tethered "main" ContentCollection.
        $document = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Page with content'],
        );

        // Find the tethered "main" ContentCollection child.
        $documentChildren = $this->nodeReadService->getChildren($document['nodeAggregateId']);
        $mainCollection = null;
        foreach ($documentChildren as $child) {
            if ($child['nodeTypeName'] === 'Neos.Neos:ContentCollection') {
                $mainCollection = $child;
                break;
            }
        }
        self::assertNotNull($mainCollection, 'Document must have a tethered "main" ContentCollection');

        // Create three text content nodes inside the collection.
        $text1 = $this->nodeWriteService->createNode(
            $mainCollection['nodeAggregateId'],
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'First paragraph'],
        );
        $text2 = $this->nodeWriteService->createNode(
            $mainCollection['nodeAggregateId'],
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'Second paragraph'],
        );
        $text3 = $this->nodeWriteService->createNode(
            $mainCollection['nodeAggregateId'],
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'Third paragraph'],
        );

        self::assertTrue($text1['success']);
        self::assertTrue($text2['success']);
        self::assertTrue($text3['success']);

        // Verify all three appear as children of the collection.
        $contentChildren = $this->nodeReadService->getChildren(
            $mainCollection['nodeAggregateId'],
            'GesagtGetan.NeosMcp:Testing.Content.Text',
        );
        self::assertCount(3, $contentChildren);

        $texts = [];
        foreach ($contentChildren as $node) {
            $text = $node['properties']['text'] ?? null;
            self::assertIsString($text);
            $texts[] = $text;
        }
        self::assertContains('First paragraph', $texts);
        self::assertContains('Second paragraph', $texts);
        self::assertContains('Third paragraph', $texts);

        // Verify they also appear via findNodes from the site root.
        $found = $this->nodeReadService->findNodes(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Content.Text',
        );
        self::assertCount(3, $found);
    }

    /**
     * @test
     */
    public function findAndReplacePropertyDryRunDoesNotModify(): void
    {
        $created = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Replace Me'],
        );

        $result = $this->nodeWriteService->findAndReplaceProperty(
            'GesagtGetan.NeosMcp:Testing.Document',
            'title',
            'Replace',
            'Changed',
            dryRun: true,
        );

        self::assertSame(1, $result['affectedNodes']);
        self::assertTrue($result['dryRun']);

        $node = $this->nodeReadService->getNode($created['nodeAggregateId']);
        self::assertNotNull($node);
        self::assertSame('Replace Me', $node['properties']['title']);
    }
}
