<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Exception;

use Neos\Flow\Exception;

/**
 * Thrown when league/oauth2-server rejects an OAuth request
 * (invalid client, mismatched redirect URI, bad credentials, etc.).
 */
class OAuthServerException extends Exception
{
}
