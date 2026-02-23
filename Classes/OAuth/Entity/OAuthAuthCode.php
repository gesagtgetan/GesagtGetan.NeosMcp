<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Algorithms;

/**
 * Authorization code — short-lived, persisted for single-use validation.
 *
 * Implements league's AuthCodeEntityInterface via explicit methods rather than traits,
 * because league traits use untyped properties that conflict with Flow's ORM mapping.
 *
 * @Flow\Entity
 *
 * @ORM\Table(name="gesagtgetan_neosmcp_oauth_auth_code")
 */
#[Flow\Proxy(false)]
class OAuthAuthCode implements AuthCodeEntityInterface
{
    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue(strategy="NONE")
     *
     * @ORM\Column(name="persistence_object_identifier", length=40)
     */
    protected string $Persistence_Object_Identifier;

    /** @ORM\Column(length=128, unique=true) */
    protected string $code = '';

    /** @ORM\Column(length=255) */
    protected string $clientId = '';

    /** @ORM\Column(length=255, nullable=true) */
    protected ?string $userIdentifier = null;

    /** @ORM\Column(length=2048, nullable=true) */
    protected ?string $redirectUri = null;

    /** @ORM\Column(length=1024) */
    protected string $scopes = '';

    /** @ORM\Column(type="datetime_immutable") */
    protected \DateTimeImmutable $expiresAt;

    /** @ORM\Column(type="boolean") */
    protected bool $revoked = false;

    /**
     * @var ScopeEntityInterface[]
     *
     * @Flow\Transient
     */
    private array $scopeEntities = [];

    /** @Flow\Transient */
    private ?ClientEntityInterface $clientEntity = null;

    public function __construct()
    {
        $this->Persistence_Object_Identifier = Algorithms::generateUUID();
        $this->expiresAt = new \DateTimeImmutable();
    }

    public function getIdentifier(): string
    {
        return $this->code;
    }

    /** @param mixed $identifier */
    public function setIdentifier($identifier): void
    {
        \assert(\is_string($identifier));
        $this->code = $identifier;
    }

    public function getExpiryDateTime(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void
    {
        $this->expiresAt = $dateTime;
    }

    /** @param string|int|null $identifier */
    public function setUserIdentifier($identifier): void
    {
        $this->userIdentifier = $identifier !== null ? (string) $identifier : null;
    }

    /** @return string|null */
    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function getClient(): ClientEntityInterface
    {
        assert($this->clientEntity !== null, 'Client entity must be set before access');

        return $this->clientEntity;
    }

    public function setClient(ClientEntityInterface $client): void
    {
        $this->clientEntity = $client;
        $this->clientId = $client->getIdentifier();
    }

    public function addScope(ScopeEntityInterface $scope): void
    {
        $this->scopeEntities[$scope->getIdentifier()] = $scope;
        $this->scopes = implode(' ', array_keys($this->scopeEntities));
    }

    /** @return ScopeEntityInterface[] */
    public function getScopes(): array
    {
        return array_values($this->scopeEntities);
    }

    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    /** @param string $uri */
    public function setRedirectUri($uri): void
    {
        $this->redirectUri = $uri;
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
