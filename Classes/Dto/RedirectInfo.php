<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class RedirectInfo implements \JsonSerializable
{
    /**
     * @param string $sourceUriPath Path without leading slash
     * @param string $targetUriPath Path without leading slash or an absolute URL, empty for 410 Gone
     * @param int $statusCode
     * @param string|null $host `null` when the redirect applies to every host
     * @param string $type "manual" or "generated", as the redirect storage writes it
     * @param string|null $comment
     * @param \DateTimeImmutable|null $startDateTime
     * @param \DateTimeImmutable|null $endDateTime
     * @param \DateTimeImmutable|null $creationDateTime
     * @param int $hitCounter
     * @param \DateTimeImmutable|null $lastHit
     */
    public function __construct(
        public string $sourceUriPath,
        public string $targetUriPath,
        public int $statusCode,
        public ?string $host,
        public string $type,
        public ?string $comment,
        public ?\DateTimeImmutable $startDateTime,
        public ?\DateTimeImmutable $endDateTime,
        public ?\DateTimeImmutable $creationDateTime,
        public int $hitCounter,
        public ?\DateTimeImmutable $lastHit,
    ) {
    }

    /**
     * @return array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: string|null, type: string, comment: string|null, startDateTime: string|null, endDateTime: string|null, creationDateTime: string|null, hitCounter: int, lastHit: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'sourceUriPath' => $this->sourceUriPath,
            'targetUriPath' => $this->targetUriPath,
            'statusCode' => $this->statusCode,
            'host' => $this->host,
            'type' => $this->type,
            'comment' => $this->comment,
            'startDateTime' => $this->startDateTime?->format(\DateTimeInterface::ATOM),
            'endDateTime' => $this->endDateTime?->format(\DateTimeInterface::ATOM),
            'creationDateTime' => $this->creationDateTime?->format(\DateTimeInterface::ATOM),
            'hitCounter' => $this->hitCounter,
            'lastHit' => $this->lastHit?->format(\DateTimeInterface::ATOM),
        ];
    }
}
