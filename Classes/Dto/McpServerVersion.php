<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * The installed and latest-known stable version of the MCP server package.
 *
 * Only ever built when both are comparable stable releases, so the two values
 * alone are enough to tell whether the install is current ({@see current} equal
 * to or greater than {@see latest} means up to date). getContentRepositoryInfo()
 * surfaces it so the agent sees it on its first call.
 */
#[Flow\Proxy(false)]
final readonly class McpServerVersion implements \JsonSerializable
{
    public function __construct(
        public string $current,
        public string $latest,
    ) {
    }

    /**
     * @return array{current: string, latest: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'current' => $this->current,
            'latest' => $this->latest,
        ];
    }
}
