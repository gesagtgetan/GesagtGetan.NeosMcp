<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Dto;

use GesagtGetan\NeosMcp\Dto\AssetType;
use GesagtGetan\NeosMcp\Dto\FindAssetsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FindAssetsRequestTest extends TestCase
{
    #[Test]
    public function acceptsARequestWithoutSearchTermAndCollection(): void
    {
        $request = new FindAssetsRequest(null, 'Document', null, 20, 0);

        self::assertNull($request->searchTerm);
        self::assertSame(AssetType::Document, $request->assetType);
        self::assertNull($request->collectionTitle);
    }

    /**
     * @return iterable<string, array{?string, string, ?string, int, int, int}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'empty search term' => ['', 'All', null, 20, 0, 1794960001];
        yield 'unknown asset type' => ['manual', 'Pdf', null, 20, 0, 1794960002];
        yield 'asset type in wrong case' => ['manual', 'document', null, 20, 0, 1794960002];
        yield 'empty collection title' => ['manual', 'All', '', 20, 0, 1794960003];
        yield 'limit of zero' => ['manual', 'All', null, 0, 0, 1794960004];
        yield 'limit above the maximum' => ['manual', 'All', null, FindAssetsRequest::MAX_LIMIT + 1, 0, 1794960004];
        yield 'negative offset' => ['manual', 'All', null, 20, -1, 1794960005];
    }

    #[Test]
    #[DataProvider('invalidArguments')]
    public function rejectsInvalidArguments(
        ?string $searchTerm,
        string $assetType,
        ?string $collectionTitle,
        int $limit,
        int $offset,
        int $expectedExceptionCode,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode($expectedExceptionCode);

        new FindAssetsRequest($searchTerm, $assetType, $collectionTitle, $limit, $offset);
    }
}
