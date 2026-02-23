<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Repository;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthClient;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 *
 * @method OAuthClient|null findOneByClientId(string $clientId)
 */
class OAuthClientRepository extends Repository implements ClientRepositoryInterface
{
    protected $entityClassName = OAuthClient::class;

    /**
     * @param string $clientIdentifier
     *
     * @return ClientEntityInterface|null
     */
    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        return $this->findOneByClientId($clientIdentifier);
    }

    /**
     * @param string $clientIdentifier
     * @param string|null $clientSecret
     * @param string|null $grantType
     */
    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $client = $this->findOneByClientId($clientIdentifier);

        if ($client === null) {
            return false;
        }

        if ($grantType !== null && !in_array($grantType, $client->getGrantTypes(), true)) {
            return false;
        }

        if ($client->isConfidential()) {
            if ($clientSecret === null || $client->getClientSecret() === null) {
                return false;
            }

            return password_verify($clientSecret, $client->getClientSecret());
        }

        return true;
    }
}
