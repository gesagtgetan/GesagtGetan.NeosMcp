<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Repository;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthScope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Hardcoded scope repository — only the "mcp" scope exists.
 *
 * @Flow\Scope("singleton")
 */
#[Flow\Proxy(false)]
class OAuthScopeRepository implements ScopeRepositoryInterface
{
    private const string SCOPE_MCP = 'mcp';

    /** @param string $identifier */
    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        if ($identifier === self::SCOPE_MCP) {
            return new OAuthScope(self::SCOPE_MCP);
        }

        return null;
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     * @param string $grantType
     * @param string|null $userIdentifier
     *
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null,
    ): array {
        // Always return the mcp scope regardless of what was requested.
        return [new OAuthScope(self::SCOPE_MCP)];
    }
}
