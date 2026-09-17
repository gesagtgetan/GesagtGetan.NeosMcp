# Testing

## Gotchas

- **Unit tests do not boot Flow** — `phpunit.xml.dist` bootstraps `vendor/autoload.php` only. Tests extend PHPUnit's `TestCase`, or `Tests/Unit/AbstractUnitTest` for its reflection-based `inject()`. Flow's `UnitTestCase` is not used because its bootstrap expects a full distribution layout that a standalone package repo does not have.
- **Stubs vs mocks** — PHPUnit 13 reports a notice for every `createMock()` whose result never receives an `expects()`. Use `self::createStub()` by default and reach for a mock only in the test that asserts the call. When a shared fixture in `setUp()` is a stub, a test that needs an expectation builds its own mock and wires a fresh subject (see `NodeReadServiceTest::createSubjectWithSubgraph()`).
- **ContentRepository is final** — cannot be mocked in PHPUnit 11+. We use `ContentRepositoryFacade` (an interface) instead.
- **Do NOT use Flow's global `FunctionalTests.xml`** — it uses the PHPUnit 9 schema, under which symlinked packages get discovered twice (each test runs twice). Use `Tests/TestDistribution/phpunit-functional.xml` inside the Docker test distribution instead.
- **Both PHPUnit configs exclude their abstract base class** — PHPUnit warns about abstract classes found during directory scanning and treats warnings as exit code 1.
- **Functional tests need Doctrine migrations** — `just test-functional` and the CI job run `./flow doctrine:migrate` in the test distribution before PHPUnit to create Neos/Flow ORM tables (e.g. `neos_asset_usage`) that the CR's catch-up hooks depend on. Without it every test errors with "Table 'neos_asset_usage' doesn't exist". The CR's own tables (event store, projections) are created automatically by `ContentRepositoryMaintainer::setUp()`.
- **`Tests/TestDistribution/Configuration/Testing/Settings.yaml` must set `path: ~`** — Flow's Testing defaults inherit `path: ':memory:'` from SQLite config. When both `driver` (pdo_mysql) AND `path` are non-null, `PersistenceManager::tearDown()` calls `$schemaTool->dropDatabase()` after every test, wiping all tables including the CR's event store.
- **SQLite is not supported** — the CR's DoctrineDbal adapter uses MySQL-specific SQL (`INSERT IGNORE`).
- **Node hierarchy in tests** — Neos enforces Sites → Site → Document. Tests must create a `Neos.Neos:Sites` root, then a `Testing.Site` (extends `Neos.Neos:Site`), then documents under the site.
- **Dimension space points** — use `resolveDefaultDimensionSpacePoint()` from the facade, not `DimensionSpacePoint::createWithoutDimensions()`. The empty `[]` DSP is invalid when dimensions are configured.
- **Run `doctrine:validate` after ORM entity changes** — `#[Flow\Proxy(false)]` prevents Flow from injecting the auto-generated primary key. DB entities with `Proxy(false)` need an explicit `@ORM\Id` property. `Proxy(false)` is required on entities with named constructor parameters because Flow's proxy constructor uses `func_get_args()` which breaks named argument calls.
- **Neos and Flow patch releases can ship clashing test classes** — Flow's Testing context registers every package's `Tests/Functional` classes with object management, so a mismatch between the installed Neos and Flow releases surfaces as a PHP fatal error during proxy compilation. Neos 9.1.9 (2026-09-12) made `ConfigurationValidationTest::$contextNames` static to match Flow's `9.1` branch, but the matching Flow 9.1.3 was not released, and Flow 9.1.2 still declares it non-static. Both test distributions therefore pin `neos/neos` to `<9.1.9` (`Tests/TestDistribution/composer.json`, `.circleci/composer.json`). Lift the pin once Flow 9.1.3 is out.
- **`Proxy(false)` entities must implement `PersistenceMagicInterface`** — Flow uses `DEFERRED_EXPLICIT` change tracking, so modified entities must be explicitly scheduled via `$repository->update()`. But `update()` rejects objects that don't implement `PersistenceMagicInterface` (normally introduced via AOP, which `Proxy(false)` bypasses). All `Proxy(false)` DB entities must explicitly `implements PersistenceMagicInterface`.

## Dev Dependencies

All tools are declared in this package's `require-dev` and installed by `composer install` in the package directory:

- `phpunit/phpunit` ^13.0
- `phpstan/phpstan` with `phpstan-phpunit` and `phpstan-strict-rules`
- `squizlabs/php_codesniffer`
- `friendsofphp/php-cs-fixer`
