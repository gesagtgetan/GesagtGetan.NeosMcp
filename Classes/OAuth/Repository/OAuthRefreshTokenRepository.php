<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Repository;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthRefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 *
 * @method OAuthRefreshToken|null findOneByToken(string $token)
 */
class OAuthRefreshTokenRepository extends Repository implements RefreshTokenRepositoryInterface
{
    protected $entityClassName = OAuthRefreshToken::class;

    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new OAuthRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->add($refreshTokenEntity);
    }

    /** @param string $tokenId */
    public function revokeRefreshToken($tokenId): void
    {
        $refreshToken = $this->findOneByToken($tokenId);

        if ($refreshToken !== null) {
            $refreshToken->setRevoked(true);
            $this->update($refreshToken);
        }
    }

    /** @param string $tokenId */
    public function isRefreshTokenRevoked($tokenId): bool
    {
        $refreshToken = $this->findOneByToken($tokenId);

        if ($refreshToken === null) {
            return true;
        }

        return $refreshToken->isRevoked();
    }
}
