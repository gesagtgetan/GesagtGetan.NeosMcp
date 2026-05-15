<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class FindAndReplaceRequest
{
    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function __construct(
        public string $search,
        public string $replace,
        public ?string $nodeTypeName,
        public ?string $propertyName,
        public bool $dryRun,
        public ?array $dimensionSpacePoint,
    ) {
        if ($search === '') {
            throw new \InvalidArgumentException('FindAndReplaceRequest.search must not be empty', 1779900030);
        }
    }
}
