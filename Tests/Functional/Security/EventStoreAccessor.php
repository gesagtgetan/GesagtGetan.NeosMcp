<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Security;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\EventStore\EventStoreInterface;

final readonly class EventStoreAccessor implements ContentRepositoryServiceInterface
{
    public function __construct(
        public EventStoreInterface $eventStore,
    ) {
    }
}
