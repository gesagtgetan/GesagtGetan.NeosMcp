<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Exception;

use Neos\Flow\Exception;

/**
 * Thrown when OAuth setup or configuration is invalid
 * (missing settings, key generation failure, unreadable key files, etc.).
 */
class OAuthSetupException extends Exception
{
}
