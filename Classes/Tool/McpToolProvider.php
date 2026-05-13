<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\ServerBuilder;

/**
 * Contract for classes that contribute MCP tools to the Neos MCP server.
 *
 * Any class in any Flow package that implements this interface is automatically
 * picked up by {@see McpToolProviderRegistry} via Flow's `ReflectionService` and
 * its tools are registered with the running server. No Settings.yaml entry is
 * required.
 *
 * Typical implementation: the provider builds a small per-request handler from
 * the {@see McpRequestContext} (or uses `$this` if it doesn't need request state),
 * registers that handler in the supplied container under its class name, then
 * uses {@see McpToolReflector::register()} to wire up every `#[McpTool]` method
 * on the handler.
 */
interface McpToolProvider
{
    public function registerTools(
        ServerBuilder $builder,
        BasicContainer $container,
        McpRequestContext $context,
    ): ServerBuilder;
}
