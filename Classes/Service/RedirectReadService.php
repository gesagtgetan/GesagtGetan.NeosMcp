<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\Dto\FindRedirectsRequest;
use GesagtGetan\NeosMcp\Dto\RedirectInfo;
use GesagtGetan\NeosMcp\Dto\RedirectInfoCollection;
use GesagtGetan\NeosMcp\Dto\RedirectSearchResult;
use Neos\Flow\Annotations as Flow;
use Neos\RedirectHandler\RedirectInterface;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;

/**
 * Searches the redirects of the Neos redirect handler.
 *
 * Redirects live outside the Content Repository, so results are the same in every
 * workspace and dimension.
 */
#[Flow\Scope('singleton')]
final readonly class RedirectReadService
{
    public function __construct(
        private RedirectStorageInterface $redirectStorage,
    ) {
    }

    public function findRedirects(FindRedirectsRequest $request): RedirectSearchResult
    {
        $host = $request->host === null ? null : $this->requireHost($request->host);

        // The storage API has no search or paging, so both happen while streaming its results.
        $total = 0;
        $redirectsOnPage = [];
        foreach ($this->redirectStorage->getAll($host, false, $request->redirectType->storageType()) as $redirect) {
            if (!$redirect instanceof RedirectInterface || !self::matchesSearchTerm($redirect, $request->searchTerm)) {
                continue;
            }
            if ($total >= $request->offset && count($redirectsOnPage) < $request->limit) {
                $redirectsOnPage[] = self::toRedirectInfo($redirect);
            }
            ++$total;
        }

        return new RedirectSearchResult(
            total: $total,
            limit: $request->limit,
            offset: $request->offset,
            redirects: new RedirectInfoCollection(...$redirectsOnPage),
        );
    }

    /**
     * Resolves the host as the storage spells it: the storage API filters by host without
     * promising to ignore case (the database storage does, through its collation).
     */
    private function requireHost(string $host): string
    {
        $knownHosts = array_values(array_filter(
            $this->redirectStorage->getDistinctHosts(),
            static fn (mixed $knownHost): bool => is_string($knownHost),
        ));
        foreach ($knownHosts as $knownHost) {
            if (mb_strtolower($knownHost) === mb_strtolower($host)) {
                return $knownHost;
            }
        }

        throw new \InvalidArgumentException(sprintf('No redirects for host "%s". Hosts with redirects: %s. Omit the host to include redirects that apply to every host.', $host, $knownHosts === [] ? '(none)' : implode(', ', $knownHosts)), 1795539806);
    }

    /**
     * Paths are stored without leading slash. Matching against the path with one lets a
     * search for "/old-page" find the top-level "old-page" as well as "news/old-page".
     */
    private static function matchesSearchTerm(RedirectInterface $redirect, ?string $searchTerm): bool
    {
        return $searchTerm === null
            || mb_stripos('/' . $redirect->getSourceUriPath(), $searchTerm) !== false
            || mb_stripos('/' . $redirect->getTargetUriPath(), $searchTerm) !== false;
    }

    private static function toRedirectInfo(RedirectInterface $redirect): RedirectInfo
    {
        return new RedirectInfo(
            sourceUriPath: $redirect->getSourceUriPath(),
            targetUriPath: $redirect->getTargetUriPath(),
            statusCode: $redirect->getStatusCode(),
            host: $redirect->getHost(),
            type: $redirect->getType(),
            comment: $redirect->getComment(),
            startDateTime: self::toImmutable($redirect->getStartDateTime()),
            endDateTime: self::toImmutable($redirect->getEndDateTime()),
            creationDateTime: self::toImmutable($redirect->getCreationDateTime()),
            hitCounter: $redirect->getHitCounter(),
            lastHit: self::toImmutable($redirect->getLastHit()),
        );
    }

    private static function toImmutable(?\DateTimeInterface $dateTime): ?\DateTimeImmutable
    {
        return $dateTime === null ? null : \DateTimeImmutable::createFromInterface($dateTime);
    }
}
