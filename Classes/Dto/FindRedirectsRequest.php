<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class FindRedirectsRequest
{
    public const MAX_LIMIT = 100;

    public RedirectType $redirectType;

    public function __construct(
        public ?string $searchTerm,
        public ?string $host,
        string $redirectType,
        public int $limit,
        public int $offset,
    ) {
        if ($searchTerm === '') {
            throw new \InvalidArgumentException('FindRedirectsRequest.searchTerm must not be empty string (use null to list all redirects)', 1795539801);
        }
        if ($host === '') {
            throw new \InvalidArgumentException('FindRedirectsRequest.host must not be empty string (use null for every host)', 1795539802);
        }
        $this->redirectType = RedirectType::tryFrom($redirectType)
            ?? throw new \InvalidArgumentException(sprintf('FindRedirectsRequest.redirectType must be one of %s, got "%s"', implode(', ', RedirectType::values()), $redirectType), 1795539803);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('FindRedirectsRequest.limit must be between 1 and %d, got %d', self::MAX_LIMIT, $limit), 1795539804);
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException(sprintf('FindRedirectsRequest.offset must be 0 or greater, got %d', $offset), 1795539805);
        }
    }
}
