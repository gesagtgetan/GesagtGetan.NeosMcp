<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\DefaultContentRepositoryFacade;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateWorkspace;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Tests\FunctionalTestCase;

/**
 * Base class for functional tests that need a working Content Repository.
 *
 * Prerequisites:
 *   - A separate test database must be available (see Configuration/Testing/Settings.yaml)
 *   - Doctrine migrations must be run once against that database in Testing context
 *     (e.g. FLOW_CONTEXT=Testing ./flow doctrine:migrate) to create Neos/Flow ORM
 *     tables like neos_asset_usage that the CR's catch-up hooks depend on.
 *
 * Each test gets a clean Content Repository with:
 *   - a "live" root workspace
 *   - a Neos.Neos:Sites root node
 *   - a GesagtGetan.NeosMcp:Testing.Site node (accessible via self::$siteNodeId)
 *
 * The Testing.Site and Testing.Document node types are defined in
 * Configuration/Testing/NodeTypes.yaml and only available in Testing context.
 */
abstract class AbstractFunctionalTest extends FunctionalTestCase
{
    protected ContentRepository $contentRepository;
    protected ContentRepositoryFacade $facade;

    /** Node ID of the test site — use this as parent when creating test documents. */
    protected static NodeAggregateId $siteNodeId;

    private static bool $contentRepositoryIsSetUp = false;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->objectManager->get(ContentRepositoryRegistry::class);
        $crId = ContentRepositoryId::fromString('default');

        // Reset the factory to get a fresh CR instance per test. Without this,
        // stale projection state from a previous test could leak into the next one.
        $registry->resetFactoryInstance($crId);
        $this->contentRepository = $registry->get($crId);
        $this->facade = new DefaultContentRepositoryFacade($this->contentRepository);

        /** @var ContentRepositoryMaintainer $maintainer */
        $maintainer = $registry->buildService($crId, new ContentRepositoryMaintainerFactory());

        // setUp() creates the CR's own tables (event store, projections).
        // This is separate from Flow's Doctrine migrations which create ORM tables.
        if (!self::$contentRepositoryIsSetUp) {
            $maintainer->setUp();
            self::$contentRepositoryIsSetUp = true;
        }

        // prune() deletes all events and resets projections, giving each test
        // a completely empty Content Repository.
        $maintainer->prune();

        $this->contentRepository->handle(
            CreateRootWorkspace::create(
                WorkspaceName::forLive(),
                ContentStreamId::create(),
            ),
        );

        // Neos enforces a strict node hierarchy: Sites → Site → Document.
        // Neos.Neos:Sites only allows Neos.Neos:Site children (via constraints),
        // so we must create an intermediate site node before any documents.
        $sitesRootId = NodeAggregateId::fromString('sites-root');
        $this->contentRepository->handle(
            CreateRootNodeAggregateWithNode::create(
                WorkspaceName::forLive(),
                $sitesRootId,
                NodeTypeName::fromString('Neos.Neos:Sites'),
            ),
        );

        $dsp = $this->resolveDefaultDimensionSpacePoint();
        self::$siteNodeId = NodeAggregateId::fromString('test-site');
        $this->contentRepository->handle(
            CreateNodeAggregateWithNode::create(
                WorkspaceName::forLive(),
                self::$siteNodeId,
                NodeTypeName::fromString('GesagtGetan.NeosMcp:Testing.Site'),
                OriginDimensionSpacePoint::fromDimensionSpacePoint($dsp),
                $sitesRootId,
            ),
        );
    }

    protected function resolveDefaultDimensionSpacePoint(): DimensionSpacePoint
    {
        foreach ($this->facade->getDimensionSpacePoints() as $point) {
            return $point;
        }

        return DimensionSpacePoint::createWithoutDimensions();
    }

    protected function createTestWorkspace(WorkspaceName $workspaceName): void
    {
        $this->contentRepository->handle(
            CreateWorkspace::create(
                $workspaceName,
                WorkspaceName::forLive(),
                ContentStreamId::create(),
            ),
        );
    }
}
