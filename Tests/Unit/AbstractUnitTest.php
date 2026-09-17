<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Base class for unit tests that need to populate Flow-injected properties
 * on a subject without booting Flow.
 */
abstract class AbstractUnitTest extends TestCase
{
    /**
     * Sets a property on the target object by reflection, bypassing visibility.
     *
     * Replaces Flow's dependency injection for `#[Flow\Inject]` and `#[Flow\InjectConfiguration]`
     * properties in unit tests.
     */
    protected function inject(object $target, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        if (!$reflection->hasProperty($propertyName)) {
            throw new \RuntimeException(sprintf('Cannot inject "%s": %s has no such property', $propertyName, $target::class), 1758200000);
        }

        $reflection->getProperty($propertyName)->setValue($target, $value);
    }
}
