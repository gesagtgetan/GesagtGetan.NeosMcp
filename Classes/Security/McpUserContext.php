<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Security;

use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;
use Neos\Flow\Annotations as Flow;

/**
 * Request-scoped holder for the CR UserId extracted from the MCP JWT token.
 *
 * Set by {@see \GesagtGetan\NeosMcp\Controller\McpHttpController} before dispatching
 * the MCP request, read by {@see McpAwareAuthProvider} during CR command handling.
 */
#[Flow\Scope('singleton')]
class McpUserContext
{
    private ?UserId $userId = null;

    public function getUserId(): ?UserId
    {
        return $this->userId;
    }

    public function setUserId(UserId $userId): void
    {
        $this->userId = $userId;
    }

    public function clear(): void
    {
        $this->userId = null;
    }
}
