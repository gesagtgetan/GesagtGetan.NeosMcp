# CLAUDE.md — GesagtGetan.NeosMcp

MCP server exposing the Neos 9 Content Repository to LLMs. PHP 8.3+, Neos 9.

Repo: `git@github.com:gesagtgetan/neos-mcp.git` (separate git repo inside `DistributionPackages/GesagtGetan.NeosMcp/`).

## Open TODOs

- **Proper PSR-3 logging** — Integrate the GesagtGetan logging package so that logs (including exception traces) are forwarded to 3rd-party services like Datadog.
- **Use authenticated Neos session for CR writes** — Currently the MCP server bypasses Flow's security context via `withoutAuthorizationChecks()` and writes directly to the shared workspace. Now that OAuth is backed by real Neos sessions, use the authenticated user's security context to create and edit nodes in the Content Repository, respecting their actual permissions.
- **OAuth token cleanup command** — Add a CLI command (e.g., `./flow oauth:cleanup`) to delete expired and revoked auth codes and refresh tokens from the database. Run periodically via cron.
- **Upgrade to league/oauth2-server ^9** — Currently pinned to ^8.5 due to `lcobucci/jwt` version conflict with `flownative/openidconnect-client` (requires ^4.1). Once Flownative supports `lcobucci/jwt ^5`, upgrade to league v9 (changes: `__toString()` → `toString()`, `CryptKey` → `CryptKeyInterface`).
- **ChatGPT connector support** — The goal is to serve both Claude and ChatGPT from the same endpoints. Adding ChatGPT requires: (1) extend `corsAllowedOrigins` with ChatGPT's origin(s), (2) add ChatGPT's callback URL to `client.knownRedirectUris`.
- **CircleCI integration** — Add `.circleci/config.yml` to the repo. Two jobs: (1) static analysis + unit tests (`just check` + `just test-unit`, no DB needed), (2) functional tests (`just test-functional`, needs MariaDB service, full Flow bootstrap with `doctrine:migrate` + `cr:setup`). Dev dependencies (phpunit, phpstan, etc.) are provided by the host project, so CI needs a minimal Neos/Flow checkout. Reference the host project's CircleCI config for the Flow bootstrap pattern.
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

### OAuth 2.0 Authorization Server (`Classes/OAuth/`)

Built on `league/oauth2-server` ^8.5. Implements the OAuth 2.0 authorization code grant with PKCE for Claude's remote MCP connector requirements.

**Flow**: Claude discovers endpoints via `.well-known` metadata → user authorizes in browser (Neos session) → Claude exchanges auth code for JWT access token → JWT validated on each MCP request. Client is pre-registered via Settings.yaml (no Dynamic Client Registration).

| Layer | Classes | Notes |
|-------|---------|-------|
| Entities | `OAuthClient`, `OAuthAuthCode`, `OAuthRefreshToken` (Flow entities), `OAuthAccessToken` (in-memory, JWT), `OAuthScope`, `OAuthUser` (value objects) | All use `#[Flow\Proxy(false)]`; DB entities have explicit `@ORM\Id` since Flow doesn't inject PK on unproxied classes |
| Repositories | `OAuthClientRepository`, `OAuthAuthCodeRepository`, `OAuthRefreshTokenRepository` (Flow repos), `OAuthAccessTokenRepository` (no-op), `OAuthScopeRepository` (hardcoded "mcp") | Implement league's repository interfaces |
| Service | `OAuthServerFactory` — creates league's `AuthorizationServer` + `ResourceServer`, auto-generates RSA keys | Keys stored in `Data/Persistent/GesagtGetan.NeosMcp/` |
| Controllers | `OAuthMetadataController` (.well-known), `OAuthAuthorizeController` (consent), `OAuthTokenController` (token exchange) | All under `OAuth\Controller` subpackage |

**Configuration** (`Settings.yaml`): `GesagtGetan.NeosMcp.oauth.enabled` (default false), `.issuer`, `.client.id`, `.client.secret`, `.client.knownRedirectUris`, `.corsAllowedOrigins`, `.accessTokenLifetime`.

**Security** (`Policy.yaml`): `McpUser` role (extends `AbstractEditor`) required for authorization endpoint. All other OAuth endpoints are public (Everybody).

**Staging basic auth** (`Web/.htaccess`): The proserverXXXX or getan.at domains require HTTP basic auth. OAuth/MCP routes are exempted via a `%{THE_REQUEST}` exclusion in the `<If>` condition so Claude can reach `/.well-known/oauth-*`, `/oauth/token`, and `/api/mcp` without basic auth credentials. The authorization endpoint (`GET /api/mcp`) is also exempted but requires a Neos session, so there is no security gap.

## Testing Gotchas

- **ContentRepository is final** — cannot be mocked in PHPUnit 11+. We use `ContentRepositoryFacade` (an interface) instead. PHPUnit 10 still allows mocking final classes but with deprecation warnings.
- **Do NOT use Flow's global `FunctionalTests.xml`** — it uses the PHPUnit 9 schema. Under PHPUnit 10, symlinked packages get discovered twice (each test runs twice). Use the package's own `phpunit-functional.xml.dist` instead.
- **`phpunit-functional.xml.dist` excludes `AbstractFunctionalTest.php`** — PHPUnit 10 warns about abstract classes found during directory scanning and treats warnings as exit code 1.
- **Functional tests need Doctrine migrations** — run `FLOW_CONTEXT=Testing ./flow doctrine:migrate` once to create Neos/Flow ORM tables (e.g. `neos_asset_usage`) that the CR's catch-up hooks depend on. The CR's own tables (event store, projections) are created automatically by `ContentRepositoryMaintainer::setUp()`.
- **`Configuration/Testing/Settings.yaml` (host project) must set `path: ~`** — Flow's Testing defaults inherit `path: ':memory:'` from SQLite config. When both `driver` (pdo_mysql) AND `path` are non-null, `PersistenceManager::tearDown()` calls `$schemaTool->dropDatabase()` after every test, wiping all tables including the CR's event store.
- **SQLite is not supported** — the CR's DoctrineDbal adapter uses MySQL-specific SQL (`INSERT IGNORE`).
- **Node hierarchy in tests** — Neos enforces Sites → Site → Document. Tests must create a `Neos.Neos:Sites` root, then a `Testing.Site` (extends `Neos.Neos:Site`), then documents under the site.
- **Dimension space points** — use `resolveDefaultDimensionSpacePoint()` from the facade, not `DimensionSpacePoint::createWithoutDimensions()`. The empty `[]` DSP is invalid when dimensions are configured.
- **Run `doctrine:validate` after ORM entity changes** — `#[Flow\Proxy(false)]` prevents Flow from injecting the auto-generated primary key. DB entities with `Proxy(false)` need an explicit `@ORM\Id` property. `Proxy(false)` is required on entities with named constructor parameters because Flow's proxy constructor uses `func_get_args()` which breaks named argument calls.

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
