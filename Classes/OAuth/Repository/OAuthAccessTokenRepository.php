<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Repository;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthAccessToken;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Neos\Flow\Annotations as Flow;

/**
 * No-op access token repository — tokens are JWTs, validated by signature only.
 *
 * @Flow\Scope("singleton")
 */
#[Flow\Proxy(false)]
class OAuthAccessTokenRepository implements AccessTokenRepositoryInterface
{
    /**
     * @param ScopeEntityInterface[] $scopes
     * @param mixed $userIdentifier
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new OAuthAccessToken();
        $token->setClient($clientEntity);

        if (is_string($userIdentifier) || is_int($userIdentifier)) {
            $token->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        // No-op: access tokens are JWTs, not persisted.
    }

    /** @param string $tokenId */
    public function revokeAccessToken($tokenId): void
    {
        // No-op: JWT tokens cannot be revoked individually.
    }

    /** @param string $tokenId */
    public function isAccessTokenRevoked($tokenId): bool
    {
        // JWT tokens are always valid until expiry.
        return false;
    }
}
