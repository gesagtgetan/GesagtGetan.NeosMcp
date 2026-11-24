<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Service;

use GesagtGetan\NeosMcp\Dto\FindRedirectsRequest;
use GesagtGetan\NeosMcp\Dto\RedirectInfo;
use GesagtGetan\NeosMcp\Dto\RedirectSearchResult;
use GesagtGetan\NeosMcp\Service\RedirectReadService;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\RedirectHandler\RedirectInterface;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use PHPUnit\Framework\Attributes\Test;

class RedirectReadServiceTest extends FunctionalTestCase
{
    private RedirectReadService $redirectReadService;
    private RedirectStorageInterface $redirectStorage;
    private PersistenceManagerInterface $persistence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redirectReadService = $this->objectManager->get(RedirectReadService::class);
        $this->redirectStorage = $this->objectManager->get(RedirectStorageInterface::class);
        $this->persistence = $this->objectManager->get(PersistenceManagerInterface::class);

        // The test database is not dropped between tests, so clear what earlier tests left behind.
        $this->redirectStorage->removeAll();
        $this->persistence->persistAll();
    }

    #[Test]
    public function findsARedirectByPartOfItsSourceOrTargetPath(): void
    {
        $this->addRedirect('products/x200-old', 'products/x200');
        $this->addRedirect('specials', 'campaigns/X200-launch');
        $this->addRedirect('about-us', 'company');

        $result = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(searchTerm: 'x200'));

        self::assertSame(['products/x200-old', 'specials'], self::sourceUriPaths($result));
    }

    #[Test]
    public function aSearchTermWithLeadingSlashFindsTopLevelPaths(): void
    {
        $this->addRedirect('old-page', 'new-page');
        $this->addRedirect('news/old-page-2024', 'news');
        $this->addRedirect('folded-page', 'new-page');

        $result = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(searchTerm: '/old-page'));

        self::assertSame(['news/old-page-2024', 'old-page'], self::sourceUriPaths($result));
    }

    #[Test]
    public function hostLimitsTheResultsToThatHostIgnoringCase(): void
    {
        $this->addRedirect('on-shop', 'target', hosts: ['shop.example.com']);
        $this->addRedirect('on-www', 'target', hosts: ['www.example.com']);
        $this->addRedirect('on-every-host', 'target');

        $shop = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(host: 'Shop.Example.com'));
        $all = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest());

        self::assertSame(['on-shop'], self::sourceUriPaths($shop));
        self::assertSame(['on-every-host', 'on-shop', 'on-www'], self::sourceUriPaths($all));
    }

    #[Test]
    public function unknownHostFailsAndNamesTheHostsWithRedirects(): void
    {
        $this->addRedirect('on-shop', 'target', hosts: ['shop.example.com']);
        $this->addRedirect('on-every-host', 'target');

        try {
            $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(host: 'blog.example.com'));
            self::fail('Expected an exception for the unknown host');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(1795539806, $exception->getCode());
            self::assertStringContainsString('"blog.example.com"', $exception->getMessage());
            self::assertStringContainsString('shop.example.com', $exception->getMessage());
        }
    }

    #[Test]
    public function redirectTypeLimitsTheResultsToThatType(): void
    {
        $this->addRedirect('by-editor', 'target', type: RedirectInterface::REDIRECT_TYPE_MANUAL);
        $this->addRedirect('by-neos', 'target', type: RedirectInterface::REDIRECT_TYPE_GENERATED);

        $manual = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(redirectType: 'Manual'));
        $generated = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(redirectType: 'Generated'));

        self::assertSame(['by-editor'], self::sourceUriPaths($manual));
        self::assertSame(['by-neos'], self::sourceUriPaths($generated));
    }

    #[Test]
    public function pagesThroughTheMatchesAndReportsWhetherMoreFollow(): void
    {
        $this->addRedirect('a', 'target');
        $this->addRedirect('b', 'target');
        $this->addRedirect('c', 'target');
        $this->addRedirect('unrelated', 'elsewhere');

        $firstPage = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(searchTerm: 'target', limit: 2));
        $secondPage = $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest(searchTerm: 'target', limit: 2, offset: 2));

        self::assertSame(['a', 'b'], self::sourceUriPaths($firstPage));
        self::assertSame(3, $firstPage->total);
        self::assertTrue($firstPage->hasMore);
        self::assertSame(['c'], self::sourceUriPaths($secondPage));
        self::assertSame(3, $secondPage->total);
        self::assertFalse($secondPage->hasMore);
    }

    #[Test]
    public function describesEachRedirectWithWhereItLeadsAndWhetherItIsUsed(): void
    {
        $this->addRedirect(
            'old-page',
            'new-page',
            statusCode: 302,
            hosts: ['www.example.com'],
            comment: 'Spring campaign',
            startDateTime: new \DateTime('2026-03-01 09:00'),
            endDateTime: new \DateTime('2026-05-31 18:00'),
        );

        $redirects = iterator_to_array(
            $this->redirectReadService->findRedirects(self::buildFindRedirectsRequest())->redirects,
            false,
        );

        self::assertCount(1, $redirects);
        $redirect = $redirects[0];
        self::assertSame(
            [
                'sourceUriPath' => 'old-page',
                'targetUriPath' => 'new-page',
                'statusCode' => 302,
                'host' => 'www.example.com',
                'type' => 'manual',
                'comment' => 'Spring campaign',
                'startDateTime' => self::atomInDefaultTimezone('2026-03-01 09:00'),
                'endDateTime' => self::atomInDefaultTimezone('2026-05-31 18:00'),
                'hitCounter' => 0,
                'lastHit' => null,
            ],
            array_diff_key($redirect->jsonSerialize(), ['creationDateTime' => true]),
        );
        self::assertNotNull($redirect->creationDateTime);
    }

    /**
     * @param list<string> $hosts
     */
    private function addRedirect(
        string $sourceUriPath,
        string $targetUriPath,
        int $statusCode = 301,
        array $hosts = [],
        ?string $comment = null,
        string $type = RedirectInterface::REDIRECT_TYPE_MANUAL,
        ?\DateTime $startDateTime = null,
        ?\DateTime $endDateTime = null,
    ): void {
        $this->redirectStorage->addRedirect($sourceUriPath, $targetUriPath, $statusCode, $hosts, null, $comment, $type, $startDateTime, $endDateTime);
        $this->persistence->persistAll();
    }

    private static function buildFindRedirectsRequest(
        ?string $searchTerm = null,
        ?string $host = null,
        string $redirectType = 'All',
        int $limit = 20,
        int $offset = 0,
    ): FindRedirectsRequest {
        return new FindRedirectsRequest($searchTerm, $host, $redirectType, $limit, $offset);
    }

    /**
     * The storage keeps no time zone and reads times back in PHP's default one.
     */
    private static function atomInDefaultTimezone(string $dateTime): string
    {
        return (new \DateTimeImmutable($dateTime))->format(\DateTimeInterface::ATOM);
    }

    /**
     * @return list<string>
     */
    private static function sourceUriPaths(RedirectSearchResult $result): array
    {
        return array_map(
            static fn (RedirectInfo $redirect): string => $redirect->sourceUriPath,
            iterator_to_array($result->redirects, false),
        );
    }
}
