<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use Neos\Flow\Annotations as Flow;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\ServerBuilder;

/**
 * Registers every `#[McpTool]`-annotated public method of a handler class with
 * a {@see ServerBuilder}.
 *
 * Uses manual `withTool()` registration instead of the library's `Server::discover()`
 * filesystem scan because discovery calls `Registry::clear()` and cannot be mixed
 * with manual setup. The attribute's `description` and `annotations` are forwarded
 * explicitly — `withTool()` does not read `#[McpTool]` attributes on its own.
 */
#[Flow\Proxy(false)]
final class McpToolReflector
{
    /**
     * @param class-string $handlerClassName
     */
    public static function register(ServerBuilder $builder, string $handlerClassName): ServerBuilder
    {
        foreach ((new \ReflectionClass($handlerClassName))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(McpTool::class);
            if ($attributes === []) {
                continue;
            }

            /** @var McpTool $attr */
            $attr = $attributes[0]->newInstance();
            $builder = $builder->withTool(
                [$handlerClassName, $method->getName()],
                description: $attr->description,
                annotations: $attr->annotations,
            );
        }

        return $builder;
    }
}
