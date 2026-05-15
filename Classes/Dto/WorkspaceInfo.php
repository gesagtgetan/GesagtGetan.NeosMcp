<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class WorkspaceInfo implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $baseWorkspace,
        public string $status,
    ) {
    }

    /**
     * @return array{name: string, baseWorkspace: ?string, status: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'baseWorkspace' => $this->baseWorkspace,
            'status' => $this->status,
        ];
    }
}
