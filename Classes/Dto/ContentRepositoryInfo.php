<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ContentRepositoryInfo implements \JsonSerializable, WithRebaseWarning
{
    public function __construct(
        public string $contentRepositoryId,
        public DimensionMap $dimensions,
        public WorkspaceInfoCollection $workspaces,
        public DimensionSpacePointList $dimensionSpacePoints,
        public ?string $rebaseWarning = null,
        public ?McpServerVersion $serverVersion = null,
    ) {
    }

    public function withRebaseWarning(?string $warning): static
    {
        return new self(
            $this->contentRepositoryId,
            $this->dimensions,
            $this->workspaces,
            $this->dimensionSpacePoints,
            $warning,
            $this->serverVersion,
        );
    }

    /**
     * A copy carrying the installed and latest-known server version.
     * getContentRepositoryInfo() attaches it here, on the orientation call the
     * agent makes first, so the version is visible via a tool result regardless
     * of whether the client relays the server `instructions`.
     */
    public function withServerVersion(?McpServerVersion $serverVersion): self
    {
        return new self(
            $this->contentRepositoryId,
            $this->dimensions,
            $this->workspaces,
            $this->dimensionSpacePoints,
            $this->rebaseWarning,
            $serverVersion,
        );
    }

    public function getRebaseWarning(): ?string
    {
        return $this->rebaseWarning;
    }

    /**
     * @return array{contentRepositoryId: string, dimensions: DimensionMap, workspaces: WorkspaceInfoCollection, dimensionSpacePoints: DimensionSpacePointList, serverVersion?: McpServerVersion, _rebaseWarning?: string}
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'contentRepositoryId' => $this->contentRepositoryId,
            'dimensions' => $this->dimensions,
            'workspaces' => $this->workspaces,
            'dimensionSpacePoints' => $this->dimensionSpacePoints,
        ];

        if ($this->serverVersion !== null) {
            $payload['serverVersion'] = $this->serverVersion;
        }

        if ($this->rebaseWarning !== null) {
            $payload['_rebaseWarning'] = $this->rebaseWarning;
        }

        return $payload;
    }
}
