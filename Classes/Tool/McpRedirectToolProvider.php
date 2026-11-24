<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use GesagtGetan\NeosMcp\Dto\FindRedirectsRequest;
use GesagtGetan\NeosMcp\Dto\RedirectSearchResult;
use GesagtGetan\NeosMcp\Dto\RedirectType;
use GesagtGetan\NeosMcp\Service\RedirectReadService;
use Neos\Flow\Annotations as Flow;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\ServerBuilder;

/**
 * Built-in MCP tool provider for the redirects of the Neos redirect handler.
 *
 * Redirects are not part of the Content Repository, so this provider holds no
 * workspace state and does not rebase before a call.
 */
#[Flow\Scope('singleton')]
final readonly class McpRedirectToolProvider implements McpToolProvider
{
    public function __construct(
        private RedirectReadService $redirectReadService,
    ) {
    }

    public function registerTools(
        ServerBuilder $builder,
        BasicContainer $container,
        McpRequestContext $context,
    ): ServerBuilder {
        $container->set(self::class, $this);

        return McpToolReflector::register($builder, self::class, $context->disabledTools);
    }

    /**
     * @param string|null $searchTerm Case-insensitive substring to look for in the source and target path, e.g. "/old-page". Omit to list every redirect the other filters allow.
     * @param string|null $host Restrict to the redirects of one host (case-insensitive). Omit to include every host and the redirects that apply to all hosts.
     * @param string $redirectType "Manual" for redirects editors added in the Redirects module, "Generated" for those Neos created when a page's URL changed (default: "All")
     * @param int $limit Maximum number of results per page, 1 to 100 (default: 20)
     * @param int $offset Number of results to skip (default: 0)
     */
    #[McpTool(
        description: <<<'MCP'
            Search the HTTP redirects of the site, read-only. Matches the source and the target path.

            Use it to answer where an old URL leads, which redirects point at a page (e.g. before moving or removing it), or which redirects were added by hand.

            Paths are stored without leading slash; a search term with one (e.g. `/products/x200`) still finds the top-level path. `targetUriPath` is a path or an absolute URL, and empty for status 410 (Gone). `host` is `null` for a redirect that applies to every host. An unknown `host` fails with the list of hosts that have redirects.

            Times are ISO 8601. A redirect is only active between `startDateTime` and `endDateTime` when they are set; `hitCounter` and `lastHit` show whether it is still used. Paginate with `limit` and `offset`: `total` and `hasMore` tell you when to stop.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function findRedirects(
        ?string $searchTerm = null,
        ?string $host = null,
        #[Schema(type: 'string', enum: [RedirectType::All->value, RedirectType::Manual->value, RedirectType::Generated->value])]
        string $redirectType = 'All',
        int $limit = 20,
        int $offset = 0,
    ): RedirectSearchResult {
        return $this->redirectReadService->findRedirects(
            new FindRedirectsRequest($searchTerm, $host, $redirectType, $limit, $offset),
        );
    }
}
