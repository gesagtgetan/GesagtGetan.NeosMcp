<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Service;

use GesagtGetan\NeosMcp\Service\AssetPropertyConverter;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AssetPropertyConverterTest extends TestCase
{
    private const PDF_IDENTIFIER = '11111111-1111-4111-8111-111111111111';
    private const IMAGE_IDENTIFIER = '22222222-2222-4222-8222-222222222222';

    private AssetPropertyConverter $subject;
    private Document&Stub $pdf;
    private Image&Stub $image;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdf = self::createStub(Document::class);
        $this->image = self::createStub(Image::class);

        $assetRepository = self::createStub(AssetRepository::class);
        $assetRepository->method('findByIdentifier')->willReturnCallback(
            fn (mixed $assetIdentifier): ?Asset => match ($assetIdentifier) {
                self::PDF_IDENTIFIER => $this->pdf,
                self::IMAGE_IDENTIFIER => $this->image,
                default => null,
            },
        );

        $this->subject = new AssetPropertyConverter($assetRepository);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function propertyTypes(): iterable
    {
        yield 'asset' => [Asset::class, true];
        yield 'asset with leading backslash' => ['\\' . Asset::class, true];
        yield 'asset subclass' => [Document::class, true];
        yield 'image interface' => [ImageInterface::class, true];
        yield 'list of assets' => ['array<' . Asset::class . '>', true];
        yield 'list of images' => ['array<' . ImageInterface::class . '>', true];
        yield 'string' => ['string', false];
        yield 'date' => ['DateTime', false];
        yield 'plain array' => ['array', false];
        yield 'list of strings' => ['array<string>', false];
    }

    #[Test]
    #[DataProvider('propertyTypes')]
    public function recognizesPropertyTypesThatHoldAssets(string $propertyType, bool $holdsAssets): void
    {
        self::assertSame($holdsAssets, AssetPropertyConverter::isAssetPropertyType($propertyType));
    }

    #[Test]
    public function describesAnAssetAsAssetUriOfItsIdentifier(): void
    {
        $this->pdf->method('getIdentifier')->willReturn(self::PDF_IDENTIFIER);

        self::assertSame('asset://' . self::PDF_IDENTIFIER, AssetPropertyConverter::toReference($this->pdf));
    }

    #[Test]
    public function resolvesAnAssetUriToTheAsset(): void
    {
        $asset = $this->subject->toAssets('pdf', Asset::class, 'asset://' . self::PDF_IDENTIFIER);

        self::assertSame($this->pdf, $asset);
    }

    #[Test]
    public function resolvesABareIdentifierToTheAsset(): void
    {
        $asset = $this->subject->toAssets('pdf', Asset::class, self::PDF_IDENTIFIER);

        self::assertSame($this->pdf, $asset);
    }

    #[Test]
    public function resolvesAListOfReferencesForAListType(): void
    {
        $assets = $this->subject->toAssets(
            'attachments',
            'array<' . Asset::class . '>',
            ['asset://' . self::PDF_IDENTIFIER, 'asset://' . self::IMAGE_IDENTIFIER],
        );

        self::assertSame([$this->pdf, $this->image], $assets);
    }

    #[Test]
    public function resolvesAnEmptyListToNoAssets(): void
    {
        self::assertSame([], $this->subject->toAssets('attachments', 'array<' . Asset::class . '>', []));
    }

    /**
     * @return iterable<string, array{string, mixed, int}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'single reference for a list type' => ['array<' . Asset::class . '>', 'asset://' . self::PDF_IDENTIFIER, 1795100002];
        yield 'keyed array for a list type' => ['array<' . Asset::class . '>', ['first' => 'asset://' . self::PDF_IDENTIFIER], 1795100002];
        yield 'list for a single asset' => [Asset::class, ['asset://' . self::PDF_IDENTIFIER], 1795100003];
        yield 'number' => [Asset::class, 42, 1795100003];
        yield 'empty string' => [Asset::class, '', 1795100003];
        yield 'null inside a list' => ['array<' . Asset::class . '>', [null], 1795100003];
        yield 'unknown identifier' => [Asset::class, 'asset://33333333-3333-4333-8333-333333333333', 1795100004];
        yield 'document for an image property' => [ImageInterface::class, 'asset://' . self::PDF_IDENTIFIER, 1795100005];
        yield 'document in a list of images' => ['array<' . ImageInterface::class . '>', ['asset://' . self::IMAGE_IDENTIFIER, 'asset://' . self::PDF_IDENTIFIER], 1795100005];
    }

    #[Test]
    #[DataProvider('invalidReferences')]
    public function rejectsReferencesThePropertyCannotHold(string $propertyType, mixed $references, int $expectedExceptionCode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode($expectedExceptionCode);

        $this->subject->toAssets('someProperty', $propertyType, $references);
    }

    #[Test]
    public function acceptsAnImageForAnImageProperty(): void
    {
        $asset = $this->subject->toAssets('image', ImageInterface::class, 'asset://' . self::IMAGE_IDENTIFIER);

        self::assertSame($this->image, $asset);
    }
}
