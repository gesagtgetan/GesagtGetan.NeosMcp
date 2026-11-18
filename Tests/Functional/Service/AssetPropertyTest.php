<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Service;

use GesagtGetan\NeosMcp\Service\AssetPropertyConverter;
use GesagtGetan\NeosMcp\Service\NodeReadService;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use GesagtGetan\NeosMcp\Tests\Functional\AbstractFunctionalTest;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Repository\AssetRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Asset-typed properties written and read through the real Content Repository, which
 * serializes the asset objects on write and loads them again on read.
 */
class AssetPropertyTest extends AbstractFunctionalTest
{
    private const DOCUMENT_NODE_TYPE = 'GesagtGetan.NeosMcp:Testing.Document';

    private NodeWriteService $nodeWriteService;
    private NodeReadService $nodeReadService;

    protected function setUp(): void
    {
        parent::setUp();

        $workspaceName = WorkspaceName::fromString('test-workspace');
        $this->createTestWorkspace($workspaceName);

        $this->nodeWriteService = new NodeWriteService($this->facade, $workspaceName, $this->objectManager->get(AssetPropertyConverter::class));
        $this->nodeReadService = new NodeReadService($this->facade, $workspaceName);
    }

    #[Test]
    public function anAssetPropertySetOnCreationIsReadBackAsTheSameReference(): void
    {
        $manual = $this->addDocument('acme-x200-manual.pdf');

        $created = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            self::DOCUMENT_NODE_TYPE,
            ['title' => 'X200 manual', 'pdf' => 'asset://' . $manual->getIdentifier()],
        );

        self::assertSame('asset://' . $manual->getIdentifier(), $this->readProperty($created->nodeAggregateId, 'pdf'));
    }

    #[Test]
    public function theReferenceReadFromOneNodeCanBeWrittenToAnother(): void
    {
        $manual = $this->addDocument('acme-x200-manual.pdf');
        $source = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            self::DOCUMENT_NODE_TYPE,
            ['title' => 'Source', 'pdf' => $manual->getIdentifier()],
        );
        $target = $this->nodeWriteService->createNode(self::$siteNodeId->value, self::DOCUMENT_NODE_TYPE, ['title' => 'Target']);

        $this->nodeWriteService->setNodeProperties(
            $target->nodeAggregateId,
            ['pdf' => $this->readProperty($source->nodeAggregateId, 'pdf')],
        );

        self::assertSame('asset://' . $manual->getIdentifier(), $this->readProperty($target->nodeAggregateId, 'pdf'));
    }

    #[Test]
    public function aListOfAssetsIsReadBackAsReferencesInTheSameOrder(): void
    {
        $manual = $this->addDocument('acme-x200-manual.pdf');
        $brochure = $this->addDocument('acme-x200-brochure.pdf');
        $created = $this->nodeWriteService->createNode(self::$siteNodeId->value, self::DOCUMENT_NODE_TYPE, ['title' => 'X200']);

        $this->nodeWriteService->setNodeProperties(
            $created->nodeAggregateId,
            ['attachments' => ['asset://' . $manual->getIdentifier(), 'asset://' . $brochure->getIdentifier()]],
        );

        self::assertSame(
            ['asset://' . $manual->getIdentifier(), 'asset://' . $brochure->getIdentifier()],
            $this->readProperty($created->nodeAggregateId, 'attachments'),
        );
    }

    #[Test]
    public function nullClearsAnAssetProperty(): void
    {
        $manual = $this->addDocument('acme-x200-manual.pdf');
        $created = $this->nodeWriteService->createNode(
            self::$siteNodeId->value,
            self::DOCUMENT_NODE_TYPE,
            ['title' => 'X200 manual', 'pdf' => 'asset://' . $manual->getIdentifier()],
        );

        $this->nodeWriteService->setNodeProperties($created->nodeAggregateId, ['pdf' => null]);

        self::assertNull($this->readProperty($created->nodeAggregateId, 'pdf'));
    }

    #[Test]
    public function aDocumentIsRejectedForAnImageProperty(): void
    {
        $manual = $this->addDocument('acme-x200-manual.pdf');
        $created = $this->nodeWriteService->createNode(self::$siteNodeId->value, self::DOCUMENT_NODE_TYPE, ['title' => 'X200']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1795100005);

        $this->nodeWriteService->setNodeProperties($created->nodeAggregateId, ['image' => 'asset://' . $manual->getIdentifier()]);
    }

    #[Test]
    public function aReferenceToAnAssetThatDoesNotExistIsRejected(): void
    {
        $created = $this->nodeWriteService->createNode(self::$siteNodeId->value, self::DOCUMENT_NODE_TYPE, ['title' => 'X200']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1795100004);

        $this->nodeWriteService->setNodeProperties(
            $created->nodeAggregateId,
            ['pdf' => 'asset://33333333-3333-4333-8333-333333333333'],
        );
    }

    private function addDocument(string $filename): Document
    {
        $document = new Document(
            $this->objectManager->get(ResourceManager::class)->importResourceFromContent('content of ' . $filename, $filename),
        );
        $this->objectManager->get(AssetRepository::class)->add($document);
        $this->objectManager->get(PersistenceManagerInterface::class)->persistAll();

        return $document;
    }

    private function readProperty(string $nodeAggregateId, string $propertyName): mixed
    {
        $node = $this->nodeReadService->getNode($nodeAggregateId);
        self::assertNotNull($node);

        return $node->properties[$propertyName] ?? null;
    }
}
