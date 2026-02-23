<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Entity;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Hardcoded scope value object — only "mcp" scope exists.
 */
#[Flow\Proxy(false)]
final readonly class OAuthScope implements ScopeEntityInterface
{
    /** @param non-empty-string $identifier */
    public function __construct(
        private string $identifier,
    ) {
    }

    /** @return non-empty-string */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /** @return non-empty-string */
    public function jsonSerialize(): string
    {
        return $this->identifier;
    }
}
