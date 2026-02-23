<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Entity;

use League\OAuth2\Server\Entities\UserEntityInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Value object wrapping a Neos Account identifier for league's user entity contract.
 */
#[Flow\Proxy(false)]
final readonly class OAuthUser implements UserEntityInterface
{
    public function __construct(
        private string $identifier,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
