<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Service;

use GesagtGetan\NeosMcp\Dto\FindAndReplaceMatch;
use GesagtGetan\NeosMcp\Dto\FindAndReplaceRequest;
use GesagtGetan\NeosMcp\Dto\FindNodesRequest;
use GesagtGetan\NeosMcp\Dto\NodeInfo;
use GesagtGetan\NeosMcp\Dto\NodeInfoCollection;
use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use GesagtGetan\NeosMcp\Tests\Functional\AbstractFunctionalTest;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function createNodeAndRetrieve(): void
    {
        $result = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Created by test'],
        );

        self::assertNotEmpty($result->nodeAggregateId);

        $node = $this->nodeReadService->getNode($result->nodeAggregateId);

        self::assertNotNull($node);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $node->nodeTypeName);
        self::assertSame('Created by test', $node->properties['title']);
    }

    #[Test]
    public function setNodePropertiesUpdatesValues(): void
    {
        $createResult = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Original'],
        );

        $this->nodeWriteService->setNodeProperties(
            $createResult->nodeAggregateId,
            ['title' => 'Updated'],
        );

        $node = $this->nodeReadService->getNode($createResult->nodeAggregateId);

        self::assertNotNull($node);
        self::assertSame('Updated', $node->properties['title']);
    }

    #[Test]
    public function removeNodeSoftRemovesFromGraph(): void
    {
        $createResult = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'To Remove'],
        );

        $this->nodeWriteService->removeNode($createResult->nodeAggregateId);

        $node = $this->nodeReadService->getNode($createResult->nodeAggregateId);
        self::assertNull($node, 'Soft-removed node must not be returned by default');

        $trashedNode = $this->nodeReadService->getNode($createResult->nodeAggregateId, includeRemoved: true);
        self::assertNotNull($trashedNode, 'Soft-removed node must still be accessible with includeRemoved: true');
        self::assertSame('To Remove', $trashedNode->properties['title']);
    }

    #[Test]
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
            $parent1->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Child'],
        );

        $this->nodeWriteService->moveNode(
            $child->nodeAggregateId,
            $parent2->nodeAggregateId,
        );

        $parent1Children = $this->nodeReadService->getChildren(
            $parent1->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Document',
        );
        self::assertCount(0, $parent1Children);

        $parent2Children = $this->nodeReadService->getChildren(
            $parent2->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Document',
        );
        self::assertCount(1, $parent2Children);
        self::assertSame($child->nodeAggregateId, iterator_to_array($parent2Children)[0]->nodeAggregateId);
    }

    #[Test]
    public function findAndReplaceUpdatesMatchingNodes(): void
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

        $result = $this->nodeWriteService->findAndReplace(self::buildFindAndReplaceRequest(
            search: 'Hello',
            replace: 'Hi',
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
            propertyName: 'title',
        ));

        self::assertSame(1, $result->affectedNodes);
        self::assertFalse($result->dryRun);
        $match = iterator_to_array($result->matches)[0];
        self::assertSame('Hello World', $match->oldValue);
        self::assertSame('Hi World', $match->newValue);
        self::assertSame('title', $match->propertyName);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $match->nodeTypeName);
    }

    #[Test]
    public function createMultipleContentNodesInDocumentCollection(): void
    {
        // Create a document — this auto-creates its tethered "main" ContentCollection.
        $document = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Page with content'],
        );

        // Find the tethered "main" ContentCollection child.
        $mainCollection = self::findContentCollectionChild(
            $this->nodeReadService->getChildren($document->nodeAggregateId),
        );
        self::assertNotNull($mainCollection, 'Document must have a tethered "main" ContentCollection');

        // Create three text content nodes inside the collection.
        $this->nodeWriteService->createNode(
            $mainCollection->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'First paragraph'],
        );
        $this->nodeWriteService->createNode(
            $mainCollection->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'Second paragraph'],
        );
        $this->nodeWriteService->createNode(
            $mainCollection->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'Third paragraph'],
        );

        // Verify all three appear as children of the collection.
        $contentChildren = $this->nodeReadService->getChildren(
            $mainCollection->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Content.Text',
        );
        self::assertCount(3, $contentChildren);

        $texts = [];
        foreach ($contentChildren as $node) {
            $text = $node->properties['text'] ?? null;
            self::assertIsString($text);
            $texts[] = $text;
        }
        self::assertContains('First paragraph', $texts);
        self::assertContains('Second paragraph', $texts);
        self::assertContains('Third paragraph', $texts);

        // Verify they also appear via findNodes from the site root.
        $found = $this->nodeReadService->findNodes(self::buildFindNodesRequest(
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Content.Text',
        ));
        self::assertCount(3, $found);
    }

    #[Test]
    public function findAndReplaceDryRunDoesNotModify(): void
    {
        $created = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Replace Me'],
        );

        $result = $this->nodeWriteService->findAndReplace(self::buildFindAndReplaceRequest(
            search: 'Replace',
            replace: 'Changed',
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
            propertyName: 'title',
            dryRun: true,
        ));

        self::assertSame(1, $result->affectedNodes);
        self::assertTrue($result->dryRun);

        $node = $this->nodeReadService->getNode($created->nodeAggregateId);
        self::assertNotNull($node);
        self::assertSame('Replace Me', $node->properties['title']);
    }

    #[Test]
    public function findAndReplaceWithoutNodeTypeNameSearchesAllTypes(): void
    {
        $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Hello Document'],
        );

        // Create a document with a tethered "main" ContentCollection, then add a text content node.
        $document = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Container'],
        );
        $mainCollection = self::findContentCollectionChild(
            $this->nodeReadService->getChildren($document->nodeAggregateId),
        );
        self::assertNotNull($mainCollection);

        $this->nodeWriteService->createNode(
            $mainCollection->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'Hello Content'],
        );

        $result = $this->nodeWriteService->findAndReplace(self::buildFindAndReplaceRequest(
            search: 'Hello',
            replace: 'Hi',
            propertyName: 'title',
            dryRun: true,
        ));

        // Only the Document matches — Content.Text has no "title" property
        self::assertSame(1, $result->affectedNodes);
        $matches = iterator_to_array($result->matches);
        self::assertSame('GesagtGetan.NeosMcp:Testing.Document', $matches[0]->nodeTypeName);
    }

    #[Test]
    public function findAndReplaceWithoutPropertyNameSearchesAllStringProperties(): void
    {
        $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Hello Title', 'text' => 'Hello Body'],
        );

        $result = $this->nodeWriteService->findAndReplace(self::buildFindAndReplaceRequest(
            search: 'Hello',
            replace: 'Hi',
            nodeTypeName: 'GesagtGetan.NeosMcp:Testing.Document',
            dryRun: true,
        ));

        self::assertSame(2, $result->affectedNodes);

        $propertyNames = array_map(
            static fn (FindAndReplaceMatch $m) => $m->propertyName,
            iterator_to_array($result->matches, false),
        );
        self::assertContains('title', $propertyNames);
        self::assertContains('text', $propertyNames);
    }

    #[Test]
    public function findAndReplaceWithBothFiltersOmitted(): void
    {
        $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Foo Document'],
        );

        $document = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Container'],
        );
        $mainCollection = self::findContentCollectionChild(
            $this->nodeReadService->getChildren($document->nodeAggregateId),
        );
        self::assertNotNull($mainCollection);

        $this->nodeWriteService->createNode(
            $mainCollection->nodeAggregateId,
            'GesagtGetan.NeosMcp:Testing.Content.Text',
            ['text' => 'Foo Content'],
        );

        // No nodeTypeName, no propertyName — full wildcard
        $result = $this->nodeWriteService->findAndReplace(self::buildFindAndReplaceRequest(
            search: 'Foo',
            replace: 'Bar',
        ));

        self::assertSame(2, $result->affectedNodes);
        self::assertFalse($result->dryRun);

        $nodeTypeNames = array_map(
            static fn (FindAndReplaceMatch $m) => $m->nodeTypeName,
            iterator_to_array($result->matches, false),
        );
        self::assertContains('GesagtGetan.NeosMcp:Testing.Document', $nodeTypeNames);
        self::assertContains('GesagtGetan.NeosMcp:Testing.Content.Text', $nodeTypeNames);
    }

    #[Test]
    public function hideNodeDisablesNodeInGraph(): void
    {
        $createResult = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'To Hide'],
        );

        $this->nodeWriteService->hideNode($createResult->nodeAggregateId);

        $node = $this->nodeReadService->getNode($createResult->nodeAggregateId);
        self::assertNotNull($node);
        self::assertTrue($node->hidden, 'Hidden node must have hidden: true');
        self::assertSame('To Hide', $node->properties['title']);
    }

    #[Test]
    public function unhideNodeEnablesNodeInGraph(): void
    {
        $createResult = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'To Toggle'],
        );

        $this->nodeWriteService->hideNode($createResult->nodeAggregateId);
        $this->nodeWriteService->unhideNode($createResult->nodeAggregateId);

        $node = $this->nodeReadService->getNode($createResult->nodeAggregateId);
        self::assertNotNull($node);
        self::assertFalse($node->hidden, 'Unhidden node must have hidden: false');
    }

    /**
     * Reproduces a real-world issue: a node created in the shared workspace,
     * published to live, then deleted in live, was still visible when reading
     * from the shared workspace. The CR does not auto-rebase derived workspaces
     * when the base changes — an explicit rebase is required.
     */
    #[Test]
    public function nodeDeletedInLiveIsStaleWithoutRebase(): void
    {
        // Create a node in the shared workspace and publish it to live.
        $created = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Published then deleted'],
        );
        $nodeId = $created->nodeAggregateId;

        $this->contentRepository->handle(
            PublishWorkspace::create(WorkspaceName::fromString('test-workspace')),
        );

        // Delete the node in live.
        $liveWriteService = new NodeWriteService($this->facade, WorkspaceName::forLive());
        $liveWriteService->removeNode($nodeId);

        // Without rebase, the shared workspace still shows the deleted node.
        self::assertNotNull($this->nodeReadService->getNode($nodeId), 'Without rebase, node is still visible (stale)');
    }

    /**
     * Verifies that rebasing the shared workspace picks up deletions from live.
     * The MCP HTTP controller rebases before every request to prevent stale reads.
     */
    #[Test]
    public function nodeDeletedInLiveDisappearsAfterRebase(): void
    {
        // Create a node in the shared workspace and publish it to live.
        $created = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Published then deleted'],
        );
        $nodeId = $created->nodeAggregateId;

        $this->contentRepository->handle(
            PublishWorkspace::create(WorkspaceName::fromString('test-workspace')),
        );

        // Delete the node in live.
        $liveWriteService = new NodeWriteService($this->facade, WorkspaceName::forLive());
        $liveWriteService->removeNode($nodeId);

        // Rebase the shared workspace to pick up live changes.
        $this->contentRepository->handle(
            RebaseWorkspace::create(WorkspaceName::fromString('test-workspace')),
        );

        // After rebase, the node must be gone.
        self::assertNull($this->nodeReadService->getNode($nodeId), 'Node deleted in live must not be visible after rebase');
    }

    private static function findContentCollectionChild(NodeInfoCollection $children): ?NodeInfo
    {
        foreach ($children as $child) {
            if ($child->nodeTypeName === 'Neos.Neos:ContentCollection') {
                return $child;
            }
        }

        return null;
    }

    private static function buildFindAndReplaceRequest(
        string $search,
        string $replace,
        ?string $nodeTypeName = null,
        ?string $propertyName = null,
        bool $dryRun = false,
    ): FindAndReplaceRequest {
        return new FindAndReplaceRequest(
            search: $search,
            replace: $replace,
            nodeTypeName: $nodeTypeName,
            propertyName: $propertyName,
            dryRun: $dryRun,
            dimensionSpacePoint: null,
        );
    }

    private static function buildFindNodesRequest(
        ?string $nodeTypeName = null,
        ?string $searchTerm = null,
    ): FindNodesRequest {
        return new FindNodesRequest(
            nodeTypeName: $nodeTypeName,
            searchTerm: $searchTerm,
            parentNodeAggregateId: null,
            limit: 100,
            dimensionSpacePoint: null,
            includeRemoved: false,
        );
    }
}
