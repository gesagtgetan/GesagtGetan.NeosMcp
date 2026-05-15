<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class WorkspaceDiscardResult implements \JsonSerializable
{
    public function __construct(
        public string $workspaceName,
    ) {
    }

    /**
     * @return array{workspaceName: string, success: true}
     */
    public function jsonSerialize(): array
    {
        return [
            'workspaceName' => $this->workspaceName,
            'success' => true,
        ];
    }
}
