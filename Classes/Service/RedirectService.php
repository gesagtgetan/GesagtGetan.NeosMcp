<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use Neos\Flow\Annotations as Flow;
use Neos\RedirectHandler\RedirectInterface;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;

#[Flow\Proxy(false)]
final readonly class RedirectService
{
    public function __construct(
        private RedirectStorageInterface $redirectStorage,
    ) {
    }

    /**
     * @return list<array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: ?string, creator: ?string, comment: ?string, type: string, hitCounter: int}>
     */
    public function listRedirects(?string $host = null, ?string $match = null, int $limit = 100): array
    {
        $result = [];
        $count = 0;

        foreach ($this->redirectStorage->getAll($host) as $redirect) {
            if ($count >= $limit) {
                break;
            }

            if ($match !== null && !$this->matchesFilter($redirect, $match)) {
                continue;
            }

            $result[] = $this->serializeRedirect($redirect);
            ++$count;
        }

        return $result;
    }

    /**
     * @return array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: ?string, creator: ?string, comment: ?string, type: string, hitCounter: int}|null
     */
    public function getRedirect(string $sourceUriPath, ?string $host = null): ?array
    {
        $redirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourceUriPath, $host, false);

        if ($redirect === null) {
            return null;
        }

        return $this->serializeRedirect($redirect);
    }

    /**
     * @return array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: ?string, success: true}
     */
    public function createRedirect(
        string $sourceUriPath,
        string $targetUriPath,
        int $statusCode = 301,
        ?string $host = null,
        ?string $comment = null,
    ): array {
        if (trim($sourceUriPath) === '') {
            throw new \InvalidArgumentException('Source URI path must not be empty.', 1739800001);
        }

        if (trim($targetUriPath) === '') {
            throw new \InvalidArgumentException('Target URI path must not be empty.', 1739800002);
        }

        if (ltrim($sourceUriPath, '/') === ltrim($targetUriPath, '/')) {
            throw new \InvalidArgumentException('Source and target URI paths must not be identical.', 1739800003);
        }

        $hosts = $host !== null ? [$host] : [];
        $redirects = $this->redirectStorage->addRedirect(
            $sourceUriPath,
            $targetUriPath,
            $statusCode,
            $hosts,
            'MCP',
            $comment,
            RedirectInterface::REDIRECT_TYPE_MANUAL,
        );

        if ($redirects === []) {
            throw new \RuntimeException('Failed to create redirect: storage returned empty result.', 1739800004);
        }

        $created = $redirects[array_key_first($redirects)];

        return [
            'sourceUriPath' => $created->getSourceUriPath(),
            'targetUriPath' => $created->getTargetUriPath(),
            'statusCode' => $created->getStatusCode(),
            'host' => $created->getHost(),
            'success' => true,
        ];
    }

    /**
     * @return array{sourceUriPath: string, host: ?string, success: true}
     */
    public function removeRedirect(string $sourceUriPath, ?string $host = null): array
    {
        $existing = $this->redirectStorage->getOneBySourceUriPathAndHost($sourceUriPath, $host, false);

        if ($existing === null) {
            throw new \InvalidArgumentException(sprintf('No redirect found for source URI path "%s" and host "%s".', $sourceUriPath, $host ?? '(all)'), 1739800005);
        }

        $this->redirectStorage->removeOneBySourceUriPathAndHost($sourceUriPath, $host);

        return [
            'sourceUriPath' => $sourceUriPath,
            'host' => $host,
            'success' => true,
        ];
    }

    private function matchesFilter(RedirectInterface $redirect, string $match): bool
    {
        $lowerMatch = mb_strtolower($match);

        return str_contains(mb_strtolower($redirect->getSourceUriPath()), $lowerMatch)
            || str_contains(mb_strtolower($redirect->getTargetUriPath()), $lowerMatch);
    }

    /**
     * @return array{sourceUriPath: string, targetUriPath: string, statusCode: int, host: ?string, creator: ?string, comment: ?string, type: string, hitCounter: int}
     */
    private function serializeRedirect(RedirectInterface $redirect): array
    {
        return [
            'sourceUriPath' => $redirect->getSourceUriPath(),
            'targetUriPath' => $redirect->getTargetUriPath(),
            'statusCode' => $redirect->getStatusCode(),
            'host' => $redirect->getHost(),
            'creator' => $redirect->getCreator(),
            'comment' => $redirect->getComment(),
            'type' => $redirect->getType(),
            'hitCounter' => $redirect->getHitCounter(),
        ];
    }
}
