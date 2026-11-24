<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Dto;

use GesagtGetan\NeosMcp\Dto\FindRedirectsRequest;
use GesagtGetan\NeosMcp\Dto\RedirectType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FindRedirectsRequestTest extends TestCase
{
    #[Test]
    public function acceptsARequestWithoutSearchTermAndHost(): void
    {
        $request = new FindRedirectsRequest(null, null, 'Manual', 20, 0);

        self::assertNull($request->searchTerm);
        self::assertNull($request->host);
        self::assertSame(RedirectType::Manual, $request->redirectType);
    }

    /**
     * @return iterable<string, array{?string, ?string, string, int, int, int}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'empty search term' => ['', null, 'All', 20, 0, 1795539801];
        yield 'empty host' => ['old-page', '', 'All', 20, 0, 1795539802];
        yield 'unknown redirect type' => ['old-page', null, 'Automatic', 20, 0, 1795539803];
        yield 'redirect type as the storage spells it' => ['old-page', null, 'manual', 20, 0, 1795539803];
        yield 'limit of zero' => ['old-page', null, 'All', 0, 0, 1795539804];
        yield 'limit above the maximum' => ['old-page', null, 'All', FindRedirectsRequest::MAX_LIMIT + 1, 0, 1795539804];
        yield 'negative offset' => ['old-page', null, 'All', 20, -1, 1795539805];
    }

    #[Test]
    #[DataProvider('invalidArguments')]
    public function rejectsInvalidArguments(
        ?string $searchTerm,
        ?string $host,
        string $redirectType,
        int $limit,
        int $offset,
        int $expectedExceptionCode,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode($expectedExceptionCode);

        new FindRedirectsRequest($searchTerm, $host, $redirectType, $limit, $offset);
    }
}
