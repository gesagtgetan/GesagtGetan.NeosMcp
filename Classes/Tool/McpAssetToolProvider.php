<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use GesagtGetan\NeosMcp\Dto\AssetSearchResult;
use GesagtGetan\NeosMcp\Dto\AssetType;
use GesagtGetan\NeosMcp\Dto\FindAssetsRequest;
use GesagtGetan\NeosMcp\Service\AssetReadService;
use Neos\Flow\Annotations as Flow;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\ServerBuilder;

/**
 * Built-in MCP tool provider for the Neos media library.
 *
 * Assets are not part of the Content Repository, so this provider holds no
 * workspace state and does not rebase before a call.
 */
#[Flow\Scope('singleton')]
final readonly class McpAssetToolProvider implements McpToolProvider
{
    public function __construct(
        private AssetReadService $assetReadService,
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
     * @param string|null $searchTerm Case-insensitive substring to look for in title, filename and caption. Omit to list every asset the other filters allow.
     * @param string $assetType Kind of asset: "Document" covers PDFs and other files that are not image, video or audio (default: "All")
     * @param string|null $collectionTitle Restrict to one asset collection by its title (case-insensitive)
     * @param int $limit Maximum number of results per page, 1 to 100 (default: 20)
     * @param int $offset Number of results to skip (default: 0)
     */
    #[McpTool(
        description: <<<'MCP'
            Search the Neos media library for assets: PDFs and other documents, images, video and audio. Matches title, filename and caption.

            Editors often encode what a file is about in its filename (e.g. `acme-x200-manual.pdf`), so search for a distinctive part such as a brand or model name rather than a whole phrase. An unknown `collectionTitle` fails with the list of available collections.

            To link to an asset from rich text, use `<a href="asset://<assetIdentifier>">`, not a literal URL. A string "link" property takes the same `asset://...` value.

            Results are ordered by filename; `fileSize` is in bytes. Paginate with `limit` and `offset`: `total` and `hasMore` tell you when to stop.
            MCP,
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function findAssets(
        ?string $searchTerm = null,
        #[Schema(type: 'string', enum: [AssetType::All->value, AssetType::Image->value, AssetType::Document->value, AssetType::Video->value, AssetType::Audio->value])]
        string $assetType = 'All',
        ?string $collectionTitle = null,
        int $limit = 20,
        int $offset = 0,
    ): AssetSearchResult {
        return $this->assetReadService->findAssets(
            new FindAssetsRequest($searchTerm, $assetType, $collectionTitle, $limit, $offset),
        );
    }
}
