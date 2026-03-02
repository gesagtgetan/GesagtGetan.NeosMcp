# Testing

## Gotchas

- **ContentRepository is final** — cannot be mocked in PHPUnit 11+. We use `ContentRepositoryFacade` (an interface) instead. PHPUnit 10 still allows mocking final classes but with deprecation warnings.
- **Do NOT use Flow's global `FunctionalTests.xml`** — it uses the PHPUnit 9 schema. Under PHPUnit 10, symlinked packages get discovered twice (each test runs twice). Use the package's own `phpunit-functional.xml.dist` instead.
- **`phpunit-functional.xml.dist` excludes `AbstractFunctionalTest.php`** — PHPUnit 10 warns about abstract classes found during directory scanning and treats warnings as exit code 1.
- **Functional tests need Doctrine migrations** — run `FLOW_CONTEXT=Testing ./flow doctrine:migrate` once to create Neos/Flow ORM tables (e.g. `neos_asset_usage`) that the CR's catch-up hooks depend on. The CR's own tables (event store, projections) are created automatically by `ContentRepositoryMaintainer::setUp()`.
- **`Configuration/Testing/Settings.yaml` (host project) must set `path: ~`** — Flow's Testing defaults inherit `path: ':memory:'` from SQLite config. When both `driver` (pdo_mysql) AND `path` are non-null, `PersistenceManager::tearDown()` calls `$schemaTool->dropDatabase()` after every test, wiping all tables including the CR's event store.
- **SQLite is not supported** — the CR's DoctrineDbal adapter uses MySQL-specific SQL (`INSERT IGNORE`).
- **Node hierarchy in tests** — Neos enforces Sites → Site → Document. Tests must create a `Neos.Neos:Sites` root, then a `Testing.Site` (extends `Neos.Neos:Site`), then documents under the site.
- **Dimension space points** — use `resolveDefaultDimensionSpacePoint()` from the facade, not `DimensionSpacePoint::createWithoutDimensions()`. The empty `[]` DSP is invalid when dimensions are configured.
- **Run `doctrine:validate` after ORM entity changes** — `#[Flow\Proxy(false)]` prevents Flow from injecting the auto-generated primary key. DB entities with `Proxy(false)` need an explicit `@ORM\Id` property. `Proxy(false)` is required on entities with named constructor parameters because Flow's proxy constructor uses `func_get_args()` which breaks named argument calls.
- **`Proxy(false)` entities must implement `PersistenceMagicInterface`** — Flow uses `DEFERRED_EXPLICIT` change tracking, so modified entities must be explicitly scheduled via `$repository->update()`. But `update()` rejects objects that don't implement `PersistenceMagicInterface` (normally introduced via AOP, which `Proxy(false)` bypasses). All `Proxy(false)` DB entities must explicitly `implements PersistenceMagicInterface`.

## Required Dev Dependencies (provided by host project)

The package ships config files but not the tools. The host project must have:

- `phpunit/phpunit` ^10.5
- `phpstan/phpstan` with `phpstan-phpunit` and `phpstan-strict-rules`
- `squizlabs/php_codesniffer`
- `friendsofphp/php-cs-fixer`
