# CLAUDE.md — GesagtGetan.NeosMcp

MCP server exposing the Neos 9 Content Repository to LLMs. PHP 8.3+, Neos 9.

Repo: `git@github.com:gesagtgetan/GesagtGetan.NeosMcp.git` (separate git repo inside `DistributionPackages/GesagtGetan.NeosMcp/`).

## Using the MCP Server While Developing

The agent can drive the MCP tools end-to-end by piping JSON-RPC into `./flow mcp:server` directly — the stdio transport accepts the standard MCP handshake (`initialize` → `notifications/initialized` → `tools/call`) and returns each tool's raw `TextContent` JSON. **This is the preferred dev loop**: every invocation reads the current PHP files, so there is no reconnect dance after a code change, and the agent can script a batch of tool calls in one go (byte-shape parity checks after a refactor, or `findNodes` → `getNode` → `setNodeProperties` → `getNode` to verify a write round-trip).

For interactive exploration, the dev can also connect the stdio MCP server as a regular client in Claude Code — but every PHP change requires a `/mcp` reconnect before the client picks up the new code, so this path is best for one-off exploration rather than tight iteration.

Writes from either path land in the shared stdio workspace (see README "CLI Transport" for details). Use `getWorkspaceStatus` to see what has accumulated and `discardWorkspaceChanges` to reset between iterations so test runs don't pollute each other.

This lets you:

- **Validate changes**: After modifying tool descriptions or schemas, call the tools to confirm they work as expected.
- **Explore the content tree**: Use `findNodes`, `getChildren`, `getNodeTypeSchema` to understand how node types are structured in practice — tethered child nodes, content collections, property values.
- **Test write operations**: Create, update, and remove nodes in the stdio workspace to verify write tools behave correctly.

## Open TODOs

- **Test tool descriptions with ChatGPT** — ChatGPT is familiar with the Neos 9 Content Repository. Connect it as an MCP client and gather feedback on whether the tool descriptions need further refinement for non-Claude models.
- **Offer pagination instead of hard limits** — When tools return lists of results, prefer cursor/offset-based pagination over fixed limits. This lets the LLM request "next page" instead of silently missing results beyond the limit. Affected tools:
  - `findNodes` — has `limit` (default 100) but offset is hardcoded to `0`; add an `offset` parameter and return `total`, `count`, and `hasMore` in the response so the LLM knows whether results were truncated and can decide to paginate or refine the search.
  - `getChildren` — returns all children with no pagination; add `limit`/`offset` for nodes with many children.
  - `listNodeTypes` — returns all non-abstract node types; add pagination for large schemas.
- **Lazy/deferred tool loading** — Currently all 17 tool schemas are loaded into context on every turn. Claude.ai supports deferred loading for its managed MCP servers (Notion, Slack), but not for custom servers. Claude Code has open feature requests (https://github.com/anthropics/claude-code/issues/3036, https://github.com/anthropics/claude-code/issues/7336, https://github.com/anthropics/claude-code/issues/23508) for lazy tool loading; community workarounds exist (e.g. [claude-lazy-loading](https://github.com/machjesusmoto/claude-lazy-loading)). Revisit when the MCP protocol or Claude Code adds native support.

## Commands

All commands run from this directory (`DistributionPackages/GesagtGetan.NeosMcp/`):

```bash
just check            # phpcs + php-cs-fixer + phpstan
just fix              # Auto-fix code style
just test-unit        # Unit tests (no DB needed)
just test-functional  # Functional tests (needs test DB)
just test             # Both
```

Functional tests need a reachable MySQL/MariaDB test database (see host project's `Configuration/Testing/Settings.yaml`). If the test DB is only reachable inside a Docker network, run functional tests from within the container:

```bash
docker exec --user www-data --interactive --workdir /var/www/FireXYZCom/DistributionPackages/GesagtGetan.NeosMcp firexyz-web-server php ../../bin/phpunit -c phpunit-functional.xml.dist
```

Note: Use `php` (not `php8.4`) inside the Docker container — the container has `php` on PATH but not the versioned binary.

## Architecture

- `ContentRepositoryFacade` — interface over the final `ContentRepository` class, exists solely for unit-testability via mocks
- `DefaultContentRepositoryFacade` — production implementation, thin pass-through to real CR
- `Tool\McpToolProvider` — interface implemented by any class that contributes MCP tools. Built-in implementations live in `Tool/`; third-party Flow packages can ship their own.
- `Tool\McpToolProviderRegistry` — Flow singleton. Uses `ReflectionService` to auto-discover every `McpToolProvider` implementation in the application; controllers call `registerAll()` per request. No Settings.yaml needed.
- `Tool\McpNodeToolProvider` — built-in node tools (read, write, node-type). Prototype-scoped; `registerTools()` initializes its service dependencies from the request context, then the `#[McpTool]` methods on the same instance handle the calls.
- `Tool\McpWorkspaceToolProvider` — built-in workspace tools (status, discard). Same lifecycle as the node provider.
- `Tool\WorkspaceRebaser` — extracted helper; rebases before every tool call and produces the `_rebaseWarning` payload on conflict.
- `Tool\McpToolReflector` — static helper that scans a handler class for `#[McpTool]` methods and forwards them to `ServerBuilder::withTool()`. Use it from any provider.
- `McpCommandController` — CLI entry points: `./flow mcp:server` (stdio transport) and `./flow mcp:setup` (creates shared workspace with Neos UI metadata via `WorkspaceService::createSharedWorkspace`).
- `NodeReadService` / `NodeWriteService` / `NodeTypeService` — domain logic, stateless.
- MCP tool parameters `properties` and `dimensionSpacePoint` are native objects (with `#[Schema]` attributes), not JSON strings.
- `getNodeTypeSchema` surfaces per-property `label` and `description` from each property's `ui.label` and `ui.help.message` in NodeTypes.yaml. Add a `ui.help.message` anywhere a hint would help the LLM disambiguate similar properties (e.g. `title` vs `titleOverride`). The same string also renders as a tooltip in the Neos editor, so authoring it once serves both audiences. This package ships a few global hints in `Configuration/NodeTypes.yaml`; site/theme packages should add their own for project-specific properties.

To contribute MCP tools from another Flow package, implement `GesagtGetan\NeosMcp\Tool\McpToolProvider`. The registry will discover and dispatch it automatically — see README "Extending with custom tools".

OAuth architecture: see [`Documentation/oauth.md`](Documentation/oauth.md)

## When to Run Which Tests

- **Entity or Repository changes** → always run both unit AND functional tests. Unit tests mock persistence and will never catch ORM issues (missing `@ORM\Id`, hydration failures, `DEFERRED_EXPLICIT` gotchas).
- **Service/Controller logic** → unit tests are usually sufficient.
- **Always run `just check`** (phpcs + php-cs-fixer + phpstan) before considering work finished.
- **Validate tests catch failures** — after writing non-trivial tests, temporarily break the implementation and verify the tests actually fail. This catches false positives (tests that always pass regardless of implementation).
- Functional tests need Docker. If the test DB hasn't had migrations: `FLOW_CONTEXT=Testing ./flow doctrine:migrate`.

Testing gotchas and dev dependencies: see [`Documentation/testing.md`](Documentation/testing.md)

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
