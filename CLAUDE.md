# CLAUDE.md — GesagtGetan.NeosMcp

MCP server exposing the Neos 9 Content Repository to LLMs. PHP 8.3+, Neos 9.

Repo: `git@github.com:gesagtgetan/neos-mcp.git` (separate git repo inside `DistributionPackages/GesagtGetan.NeosMcp/`).

## Open TODOs

- **Image support via MCP** — Two features:
  1. **Image reading**: New tool `getNodeImage(nodeAggregateId, propertyName)` — loads the Image asset from Neos, returns base64 image content block so the LLM can _see_ the image. Enables batch alt-text generation (`findNodes` where `alternativeText` is empty, loop, generate alt text, `setNodeProperties`).
  2. **Image upload**: New tool `uploadImage(url, filename?)` — fetches image from URL, imports via Flow `ResourceManager`, creates `Image` asset, returns `{assetIdentifier: "..."}`. The identifier can then be used in `createNode`/`setNodeProperties` for `ImageInterface` properties. URL-fetch approach avoids binary-in-JSON problems. Check whether the CR accepts raw asset UUIDs as property values or needs explicit conversion to `Image` objects.

## Commands

All commands run from this directory (`DistributionPackages/GesagtGetan.NeosMcp/`):

```bash
just check            # phpcs + php-cs-fixer + phpstan
just fix              # Auto-fix code style
just test-unit        # Unit tests (no DB needed)
just test-functional  # Functional tests (needs test DB)
just test             # Both
```

Functional tests need a reachable MySQL/MariaDB test database (see host project's `Configuration/Testing/Settings.yaml`). If the test DB is only reachable inside a Docker network, run functional tests from within the container.

## Architecture

- `ContentRepositoryFacade` — interface over the final `ContentRepository` class, exists solely for unit-testability via mocks
- `DefaultContentRepositoryFacade` — production implementation, thin pass-through to real CR
- `McpToolProvider` — MCP tool methods with `#[McpTool]` attributes, delegates to service classes. Reflection-based auto-registration in McpCommandController (adding a tool = adding a method).
- `McpCommandController` — CLI entry points: `./flow mcp:server` (stdio transport) and `./flow mcp:setup` (creates shared workspace with Neos UI metadata via `WorkspaceService::createSharedWorkspace`)
- `NodeReadService` / `NodeWriteService` / `NodeTypeService` — domain logic, stateless
- MCP tool parameters `properties` and `dimensionSpacePoint` are native objects (with `#[Schema]` attributes), not JSON strings.

## Testing Gotchas

- **ContentRepository is final** — cannot be mocked in PHPUnit 11+. We use `ContentRepositoryFacade` (an interface) instead. PHPUnit 10 still allows mocking final classes but with deprecation warnings.
- **Do NOT use Flow's global `FunctionalTests.xml`** — it uses the PHPUnit 9 schema. Under PHPUnit 10, symlinked packages get discovered twice (each test runs twice). Use the package's own `phpunit-functional.xml.dist` instead.
- **`phpunit-functional.xml.dist` excludes `AbstractFunctionalTest.php`** — PHPUnit 10 warns about abstract classes found during directory scanning and treats warnings as exit code 1.
- **Functional tests need Doctrine migrations** — run `FLOW_CONTEXT=Testing ./flow doctrine:migrate` once to create Neos/Flow ORM tables (e.g. `neos_asset_usage`) that the CR's catch-up hooks depend on. The CR's own tables (event store, projections) are created automatically by `ContentRepositoryMaintainer::setUp()`.
- **`Configuration/Testing/Settings.yaml` (host project) must set `path: ~`** — Flow's Testing defaults inherit `path: ':memory:'` from SQLite config. When both `driver` (pdo_mysql) AND `path` are non-null, `PersistenceManager::tearDown()` calls `$schemaTool->dropDatabase()` after every test, wiping all tables including the CR's event store.
- **SQLite is not supported** — the CR's DoctrineDbal adapter uses MySQL-specific SQL (`INSERT IGNORE`).
- **Node hierarchy in tests** — Neos enforces Sites → Site → Document. Tests must create a `Neos.Neos:Sites` root, then a `Testing.Site` (extends `Neos.Neos:Site`), then documents under the site.
- **Dimension space points** — use `resolveDefaultDimensionSpacePoint()` from the facade, not `DimensionSpacePoint::createWithoutDimensions()`. The empty `[]` DSP is invalid when dimensions are configured.

## Coding Philosophy

This package adheres to high professional coding standards. The goal is always the most logical solution expressed in the most readable code.

- **Clarity over cleverness** — code should be obvious to read, not impressive to write. Prefer explicit flow over abstractions that hide intent. A few lines of straightforward code beat a clever one-liner that needs a comment to explain.
- **Minimal surface area** — only expose what's needed. Services are `final readonly`, constructors take exactly the dependencies they use, methods do one thing.
- **No dead code** — unused config keys, speculative parameters, backwards-compatibility shims, or "might need later" abstractions get removed. If it's not called, it doesn't exist.
- **Tests are documentation** — test names describe behavior, not implementation. Read the test list and you understand what the package does.
- **Fail loudly** — invalid input throws immediately with a clear message. No silent defaults, no swallowed errors, no fallback to "something reasonable."

## Coding Standards

PSR-12 (phpcs), @Symfony ruleset (php-cs-fixer), PHPStan level max with strict rules. Always run `just check` and `just test` before considering a piece of work finished.

Uses modern PHP 8.x: `final readonly class`, constructor promotion, attributes (`#[Flow\Proxy(false)]`), intersection types. No PHP 8.4-specific features — the package runs on PHP 8.3+.

## Commit Messages

Format: `PREFIX: Imperative summary` (no period, English, no em dashes).

- `STATIC` — auto-generated CSS/JS/image files only
- `FEATURE` — new functionality or behavior changes (not bugfixes)
- `BUGFIX` — fixes to existing functionality
- `REFACTOR` — no behavior change (code style, type fixes, linter fixes, comment improvements, docblocks)
- `MERGE` — merge commits
- `DOCS` — documentation files (README, CLAUDE.md, etc.), not docblocks in source
- `TOOL` — Makefiles, deployer, JS build systems
- `TEST` — test-only commits (prefer bundling with FEATURE/BUGFIX)
- `UPGRADE` — composer/node dependency upgrades
- `INITIAL` — first commit in a new repository only

## Required Dev Dependencies (provided by host project)

The package ships config files but not the tools. The host project must have:

- `phpunit/phpunit` ^10.5
- `phpstan/phpstan` with `phpstan-phpunit` and `phpstan-strict-rules`
- `squizlabs/php_codesniffer`
- `friendsofphp/php-cs-fixer`
