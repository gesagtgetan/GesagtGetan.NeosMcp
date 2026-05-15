<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * Per-request context handed to every {@see McpToolProvider} during tool registration.
 *
 * Carries the workspace and Content Repository handle the current MCP request is scoped
 * to: stdio uses the shared review workspace, HTTP uses the authenticated user's personal
 * workspace. Plugins that don't need any of these fields can ignore the parameter.
 */
#[Flow\Proxy(false)]
final readonly class McpRequestContext
{
    /**
     * @param ContentRepositoryFacade $contentRepository
     * @param WorkspaceName $workspaceName
     * @param int|null $propertyTruncateLength
     * @param list<string> $disabledTools
     */
    public function __construct(
        public ContentRepositoryFacade $contentRepository,
        public WorkspaceName $workspaceName,
        public ?int $propertyTruncateLength = null,
        public array $disabledTools = [],
    ) {
    }
}
