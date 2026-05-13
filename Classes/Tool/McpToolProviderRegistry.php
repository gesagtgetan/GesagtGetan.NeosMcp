<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\ServerBuilder;

/**
 * Auto-discovers and dispatches every {@see McpToolProvider} implementation in
 * the application.
 *
 * Plugin authors only need to implement {@see McpToolProvider} — Flow's
 * `ReflectionService` finds every class implementing the interface at compile
 * time (cached) and {@see ObjectManagerInterface::get()} instantiates each one
 * via the normal Flow DI machinery. There is no Settings.yaml registry to
 * maintain.
 *
 * Registration order is reflection-cache order, which is non-deterministic.
 * This is intentional: MCP tools are independent of each other and the server
 * exposes a flat tool list, so order does not matter.
 */
#[Flow\Scope('singleton')]
final readonly class McpToolProviderRegistry
{
    public const string INSTRUCTIONS = <<<'TXT'
        Tools utilize the Neos CMS 9 Content Repository — a typed node tree with workspaces and optional dimensions (e.g. language). Writes target the user's personal workspace (HTTP) or a shared review workspace (CLI) and are not live until published. Warn the user if you are unsure how to use a tool or unfamiliar with the Neos 9 Content Repository and its node type handling.
        TXT;

    public function __construct(
        private ReflectionService $reflectionService,
        private ObjectManagerInterface $objectManager,
    ) {
    }

    public function registerAll(
        ServerBuilder $builder,
        BasicContainer $container,
        McpRequestContext $context,
    ): ServerBuilder {
        foreach ($this->reflectionService->getAllImplementationClassNamesForInterface(McpToolProvider::class) as $providerClassName) {
            $provider = $this->objectManager->get($providerClassName);
            if (!$provider instanceof McpToolProvider) {
                continue;
            }
            $builder = $provider->registerTools($builder, $container, $context);
        }

        return $builder;
    }
}
