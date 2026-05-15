<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class WorkspaceStatus implements \JsonSerializable
{
    public function __construct(
        public string $workspaceName,
        public ?string $baseWorkspace,
        public string $status,
        public bool $hasPendingChanges,
    ) {
    }

    /**
     * @return array{workspaceName: string, baseWorkspace: ?string, status: string, hasPendingChanges: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'workspaceName' => $this->workspaceName,
            'baseWorkspace' => $this->baseWorkspace,
            'status' => $this->status,
            'hasPendingChanges' => $this->hasPendingChanges,
        ];
    }
}
