<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Entity;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use Neos\Flow\Annotations as Flow;

/**
 * In-memory access token — not persisted, encoded as JWT.
 */
#[Flow\Proxy(false)]
class OAuthAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;
}
