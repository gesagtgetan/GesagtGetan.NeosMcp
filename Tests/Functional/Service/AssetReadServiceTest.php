<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Service;

use Doctrine\ORM\EntityManagerInterface;
use GesagtGetan\NeosMcp\Dto\AssetInfo;
use GesagtGetan\NeosMcp\Dto\AssetSearchResult;
use GesagtGetan\NeosMcp\Dto\FindAssetsRequest;
use GesagtGetan\NeosMcp\Service\AssetReadService;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Model\Video;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use PHPUnit\Framework\Attributes\Test;

class AssetReadServiceTest extends FunctionalTestCase
{
    private AssetReadService $assetReadService;
    private AssetRepository $assetRepository;
    private AssetCollectionRepository $assetCollectionRepository;
    private TagRepository $tagRepository;
    private ResourceManager $resourceManager;
    private PersistenceManagerInterface $persistence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assetReadService = $this->objectManager->get(AssetReadService::class);
        $this->assetRepository = $this->objectManager->get(AssetRepository::class);
        $this->assetCollectionRepository = $this->objectManager->get(AssetCollectionRepository::class);
        $this->tagRepository = $this->objectManager->get(TagRepository::class);
        $this->resourceManager = $this->objectManager->get(ResourceManager::class);
        $this->persistence = $this->objectManager->get(PersistenceManagerInterface::class);

        // The test database is not dropped between tests, so clear what earlier tests left behind.
        foreach ($this->assetRepository->findAll() as $asset) {
            self::assertInstanceOf(AssetInterface::class, $asset);
            $this->assetRepository->removeWithoutUsageChecks($asset);
        }
        $this->assetCollectionRepository->removeAll();
        $this->tagRepository->removeAll();
        $this->persistence->persistAll();
    }

    #[Test]
    public function findsAnAssetByPartOfItsFilename(): void
    {
        $this->addDocument('acme-x200-manual.pdf');
        $this->addDocument('other-brand-brochure.pdf');
        $this->persistAndForget();

        $result = $this->assetReadService->findAssets(self::buildFindAssetsRequest(searchTerm: 'X200'));

        self::assertSame(['acme-x200-manual.pdf'], self::filenames($result));
    }

    #[Test]
    public function findsAnAssetByItsTitleOrCaption(): void
    {
        $this->addDocument('a.pdf', title: 'Operating manual X200');
        $this->addDocument('b.pdf', caption: 'Covers the X200 series');
        $this->addDocument('c.pdf', title: 'Unrelated');
        $this->persistAndForget();

        $result = $this->assetReadService->findAssets(self::buildFindAssetsRequest(searchTerm: 'x200'));

        self::assertSame(['a.pdf', 'b.pdf'], self::filenames($result));
    }

    #[Test]
    public function listsAllAssetsOrderedByFilenameWhenNoSearchTermIsGiven(): void
    {
        $this->addDocument('charlie.pdf');
        $this->addDocument('alpha.pdf');
        $this->addDocument('bravo.pdf');
        $this->persistAndForget();

        $result = $this->assetReadService->findAssets(self::buildFindAssetsRequest());

        self::assertSame(['alpha.pdf', 'bravo.pdf', 'charlie.pdf'], self::filenames($result));
    }

    #[Test]
    public function assetTypeLimitsTheResultsToThatKind(): void
    {
        $this->addDocument('x200-manual.pdf');
        $this->addAsset(new Video($this->resourceManager->importResourceFromContent('video', 'x200-teaser.mp4')));
        $this->persistAndForget();

        $documents = $this->assetReadService->findAssets(self::buildFindAssetsRequest(searchTerm: 'x200', assetType: 'Document'));
        $videos = $this->assetReadService->findAssets(self::buildFindAssetsRequest(searchTerm: 'x200', assetType: 'Video'));
        $all = $this->assetReadService->findAssets(self::buildFindAssetsRequest(searchTerm: 'x200'));

        self::assertSame(['x200-manual.pdf'], self::filenames($documents));
        self::assertSame(['x200-teaser.mp4'], self::filenames($videos));
        self::assertSame(['x200-manual.pdf', 'x200-teaser.mp4'], self::filenames($all));
    }

    #[Test]
    public function collectionTitleLimitsTheResultsToThatCollectionIgnoringCase(): void
    {
        $manuals = new AssetCollection('Manuals');
        $this->assetCollectionRepository->add($manuals);
        $this->addDocument('in-collection.pdf', collection: $manuals);
        $this->addDocument('outside-collection.pdf');
        $this->persistAndForget();

        $result = $this->assetReadService->findAssets(self::buildFindAssetsRequest(collectionTitle: 'manuals'));

        self::assertSame(['in-collection.pdf'], self::filenames($result));
    }

    #[Test]
    public function unknownCollectionTitleFailsAndNamesTheAvailableCollections(): void
    {
        $this->assetCollectionRepository->add(new AssetCollection('Manuals'));
        $this->assetCollectionRepository->add(new AssetCollection('Brochures'));
        $this->persistAndForget();

        try {
            $this->assetReadService->findAssets(self::buildFindAssetsRequest(collectionTitle: 'Handbooks'));
            self::fail('Expected an exception for the unknown collection');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(1794960006, $exception->getCode());
            self::assertStringContainsString('"Handbooks"', $exception->getMessage());
            self::assertStringContainsString('Manuals', $exception->getMessage());
            self::assertStringContainsString('Brochures', $exception->getMessage());
        }
    }

    #[Test]
    public function pagesThroughTheMatchesAndReportsWhetherMoreFollow(): void
    {
        $this->addDocument('1.pdf');
        $this->addDocument('2.pdf');
        $this->addDocument('3.pdf');
        $this->persistAndForget();

        $firstPage = $this->assetReadService->findAssets(self::buildFindAssetsRequest(limit: 2));
        $secondPage = $this->assetReadService->findAssets(self::buildFindAssetsRequest(limit: 2, offset: 2));

        self::assertSame(['1.pdf', '2.pdf'], self::filenames($firstPage));
        self::assertSame(3, $firstPage->total);
        self::assertTrue($firstPage->hasMore);
        self::assertSame(['3.pdf'], self::filenames($secondPage));
        self::assertSame(3, $secondPage->total);
        self::assertFalse($secondPage->hasMore);
    }

    #[Test]
    public function describesEachAssetWithWhatIsNeededToLinkAndPresentIt(): void
    {
        $manuals = new AssetCollection('Manuals');
        $this->assetCollectionRepository->add($manuals);
        $tag = new Tag('acme');
        $this->tagRepository->add($tag);
        $document = $this->addDocument('acme-x200-manual.pdf', title: 'X200 manual', caption: 'Edition 2024', collection: $manuals);
        $document->addTag($tag);
        $this->persistAndForget();

        $result = $this->assetReadService->findAssets(self::buildFindAssetsRequest());

        self::assertEquals(
            [
                new AssetInfo(
                    assetIdentifier: $document->getIdentifier(),
                    filename: 'acme-x200-manual.pdf',
                    title: 'X200 manual',
                    caption: 'Edition 2024',
                    mediaType: 'application/pdf',
                    fileSize: strlen('content of acme-x200-manual.pdf'),
                    tags: ['acme'],
                    collections: ['Manuals'],
                ),
            ],
            iterator_to_array($result->assets),
        );
    }

    private function addDocument(string $filename, string $title = '', string $caption = '', ?AssetCollection $collection = null): Document
    {
        $document = new Document($this->resourceManager->importResourceFromContent('content of ' . $filename, $filename));
        $document->setTitle($title);
        $document->setCaption($caption);
        $this->addAsset($document);
        if ($collection !== null) {
            $collection->addAsset($document);
            $this->assetCollectionRepository->update($collection);
        }

        return $document;
    }

    private function addAsset(Asset $asset): void
    {
        $this->assetRepository->add($asset);
    }

    /**
     * Writes the fixtures and detaches them, so the service loads every asset from the
     * database the way it does in a real request. Without this it would get the fixture
     * objects back, whose collection membership was only set on the collection's side.
     */
    private function persistAndForget(): void
    {
        $this->persistence->persistAll();
        $this->objectManager->get(EntityManagerInterface::class)->clear();
    }

    private static function buildFindAssetsRequest(
        ?string $searchTerm = null,
        string $assetType = 'All',
        ?string $collectionTitle = null,
        int $limit = 20,
        int $offset = 0,
    ): FindAssetsRequest {
        return new FindAssetsRequest($searchTerm, $assetType, $collectionTitle, $limit, $offset);
    }

    /**
     * @return list<string>
     */
    private static function filenames(AssetSearchResult $result): array
    {
        return array_map(
            static fn (AssetInfo $asset): string => $asset->filename,
            iterator_to_array($result->assets, false),
        );
    }
}
