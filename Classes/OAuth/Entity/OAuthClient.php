<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Pre-registered OAuth client. Created from Settings.yaml on first use.
 *
 * @Flow\Entity
 *
 * @ORM\Table(name="gesagtgetan_neosmcp_oauth_client")
 */
#[Flow\Proxy(false)]
class OAuthClient implements ClientEntityInterface
{
    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue(strategy="UUID")
     *
     * @ORM\Column(name="persistence_object_identifier", length=40)
     */
    protected string $Persistence_Object_Identifier = '';

    /** @ORM\Column(length=255, unique=true) */
    protected string $clientId;

    /** @ORM\Column(length=255, nullable=true) */
    protected ?string $clientSecret = null;

    /** @ORM\Column(length=255) */
    protected string $clientName;

    /**
     * @var array<string>
     *
     * @ORM\Column(type="json")
     */
    protected array $redirectUris = [];

    /**
     * @var array<string>
     *
     * @ORM\Column(type="json")
     */
    protected array $grantTypes = [];

    /** @ORM\Column(length=50) */
    protected string $tokenEndpointAuthMethod = 'none';

    /** @ORM\Column(type="boolean") */
    protected bool $isConfidential = false;

    /** @ORM\Column(type="datetime_immutable") */
    protected \DateTimeImmutable $createdAt;

    /**
     * @param array<string> $redirectUris
     * @param array<string> $grantTypes
     */
    public function __construct(
        string $clientId,
        string $clientName,
        array $redirectUris,
        array $grantTypes = ['authorization_code', 'refresh_token'],
        string $tokenEndpointAuthMethod = 'none',
        bool $isConfidential = false,
        ?string $clientSecret = null,
    ) {
        $this->clientId = $clientId;
        $this->clientName = $clientName;
        $this->redirectUris = $redirectUris;
        $this->grantTypes = $grantTypes;
        $this->tokenEndpointAuthMethod = $tokenEndpointAuthMethod;
        $this->isConfidential = $isConfidential;
        $this->clientSecret = $clientSecret;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getIdentifier(): string
    {
        return $this->clientId;
    }

    public function getName(): string
    {
        return $this->clientName;
    }

    /** @return array<string> */
    public function getRedirectUri(): array
    {
        return $this->redirectUris;
    }

    public function isConfidential(): bool
    {
        return $this->isConfidential;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    /** @return array<string> */
    public function getGrantTypes(): array
    {
        return $this->grantTypes;
    }

    public function getTokenEndpointAuthMethod(): string
    {
        return $this->tokenEndpointAuthMethod;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
