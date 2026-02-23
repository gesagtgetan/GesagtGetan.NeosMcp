<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Refresh token — long-lived, persisted, rotated on use.
 *
 * @Flow\Entity
 *
 * @ORM\Table(name="gesagtgetan_neosmcp_oauth_refresh_token")
 */
#[Flow\Proxy(false)]
class OAuthRefreshToken implements RefreshTokenEntityInterface
{
    /** @ORM\Column(length=128, unique=true) */
    protected string $token = '';

    /** @ORM\Column(length=255) */
    protected string $accessTokenId = '';

    /** @ORM\Column(length=255) */
    protected string $clientId = '';

    /** @ORM\Column(length=255, nullable=true) */
    protected ?string $userIdentifier = null;

    /** @ORM\Column(length=1024) */
    protected string $scopes = '';

    /** @ORM\Column(type="datetime_immutable") */
    protected \DateTimeImmutable $expiresAt;

    /** @ORM\Column(type="boolean") */
    protected bool $revoked = false;

    private ?AccessTokenEntityInterface $accessTokenEntity = null;

    public function __construct()
    {
        $this->expiresAt = new \DateTimeImmutable();
    }

    public function getIdentifier(): string
    {
        return $this->token;
    }

    /** @param mixed $identifier */
    public function setIdentifier($identifier): void
    {
        \assert(\is_string($identifier));
        $this->token = $identifier;
    }

    public function getExpiryDateTime(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void
    {
        $this->expiresAt = $dateTime;
    }

    public function setAccessToken(AccessTokenEntityInterface $accessToken): void
    {
        $this->accessTokenEntity = $accessToken;
        $this->accessTokenId = $accessToken->getIdentifier();
        $this->clientId = $accessToken->getClient()->getIdentifier();
        $this->userIdentifier = is_string($accessToken->getUserIdentifier()) ? $accessToken->getUserIdentifier() : null;
        $this->scopes = implode(' ', array_map(
            static fn ($scope) => $scope->getIdentifier(),
            $accessToken->getScopes(),
        ));
    }

    public function getAccessToken(): AccessTokenEntityInterface
    {
        assert($this->accessTokenEntity !== null, 'Access token entity must be set before access');

        return $this->accessTokenEntity;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function setRevoked(bool $revoked): void
    {
        $this->revoked = $revoked;
    }
}
