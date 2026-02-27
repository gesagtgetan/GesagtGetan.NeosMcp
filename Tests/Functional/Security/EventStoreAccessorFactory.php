<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Security;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;

/**
 * @implements ContentRepositoryServiceFactoryInterface<EventStoreAccessor>
 */
final class EventStoreAccessorFactory implements ContentRepositoryServiceFactoryInterface
{
    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): EventStoreAccessor
    {
        return new EventStoreAccessor($serviceFactoryDependencies->eventStore);
    }
}
