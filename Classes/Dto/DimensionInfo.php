<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class DimensionInfo
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public string $id,
        public array $values,
    ) {
    }
}
