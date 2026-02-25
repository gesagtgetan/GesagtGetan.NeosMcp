# GesagtGetan.NeosMcp

MCP (Model Context Protocol) server for the Neos 9 Content Repository. Gives LLMs structured access to content nodes, node types, and workspaces.

## Setup

```bash
./flow mcp:setup
```

This creates the shared review workspace, generates OAuth RSA keys, and registers the OAuth client in the database. Run it during initial setup and **after every configuration change** (credentials, redirect URIs, etc.) to apply the new values.

> ⚠️ `mcp:setup` is not a one-time command. Any change to `GesagtGetan.NeosMcp.oauth.client.*` settings only takes effect after re-running `mcp:setup`.

## Usage

```bash
./flow mcp:server
```

This starts a stdio-based MCP server. The server requires the review workspace to exist — run `mcp:setup` first. Configure it as an MCP server in your LLM tool (e.g. Claude Code).

### Claude Code Configuration

```json
{
  "mcpServers": {
    "neos": {
      "command": "./flow",
      "args": ["mcp:server"]
    }
  }
}
```

## HTTP Transport (OAuth)

The package also exposes the MCP server over HTTP at `POST /api/mcp`, secured with OAuth 2.0 (authorization code grant + PKCE). This is used by Claude.ai's remote MCP connector and ChatGPT.

### Setup

1. Generate client credentials:
   ```bash
   openssl rand -hex 16   # client_id
   openssl rand -hex 32   # client_secret
   ```

2. Configure `Configuration/Production/Settings.yaml`:
   ```yaml
   GesagtGetan:
     NeosMcp:
       oauth:
         enabled: true
         issuer: 'https://your-domain.com'
         client:
           id: '<generated client_id>'
           secret: '<generated client_secret>'
   ```

3. Run the database migration to create the OAuth tables:
   ```bash
   ./flow doctrine:migrate
   ```

4. Run `./flow mcp:setup` to create the review workspace, register the OAuth client, and generate RSA keys:
   ```bash
   ./flow mcp:setup
   ```

   > ⚠️ Re-run `mcp:setup` whenever you change client credentials, redirect URIs, or any other `oauth.client.*` setting. The database is not updated automatically.

5. Store the same client_id and client_secret in your password manager. Enter them in the Claude.ai or ChatGPT connector's settings.

6. Assign the `GesagtGetan.NeosMcp:McpUser` role to Neos accounts that should be able to authorize MCP access.

7. Add `Data/Persistent/GesagtGetan.NeosMcp/` to Deployer's `shared_dirs` so the auto-generated RSA keys persist across deployments.

8. Ensure the following endpoints are publicly accessible (no basic auth, no firewall restrictions): `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`, `/oauth/token`, `/api/mcp`. If your server uses basic auth or IP restrictions, exempt these routes. For example, the proserverXXXX or getan.at staging domains use a `%{THE_REQUEST}` exclusion in `Web/.htaccess` to bypass basic auth for these paths. The authorization endpoint (`GET /api/mcp`) is also exempted but requires a Neos session, so there is no security gap.

9. Apache with `mod_proxy_fcgi` strips the `Authorization` header before it reaches PHP, causing all bearer token requests to fail silently with `401`. Add the following lines to `Web/.htaccess` inside the `<IfModule mod_rewrite.c>` block, right after `RewriteBase /`:
   ```apache
   RewriteCond %{HTTP:Authorization} .
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```
   This copies the `Authorization` header into the `HTTP_AUTHORIZATION` environment variable so PHP can read it. The `RewriteCond` ensures it only fires when the header is present.

## Configuration

In `Settings.yaml`:

```yaml
GesagtGetan:
  NeosMcp:
    contentRepositoryId: 'default'
    workspaceName: 'llm-review'
    workspaceBaseWorkspaceName: 'live'
```

## Available Tools

### Read
- `get_content_repository_info` - dimensions, workspaces, dimension space points
- `list_node_types` - list non-abstract node types (optional filter)
- `get_node_type_schema` - full schema for a node type
- `find_nodes` - search by type/term
- `get_node` - get a single node
- `get_children` - list child nodes

### Write (staged in review workspace, requires human publishing)
- `create_node` - create a node
- `set_node_properties` - update properties
- `move_node` - move to new parent
- `remove_node` - remove a node
- `find_and_replace_property` - batch find/replace in properties

### Workspace
- `get_workspace_status` - review workspace status
- `discard_workspace_changes` - discard all pending changes

### Redirects (go live immediately, no workspace staging)
- `list_redirects` - list redirects (optional host/match filter)
- `get_redirect` - get a single redirect by source path
- `create_redirect` - create a redirect
- `remove_redirect` - remove a redirect

## Workspace Rebase

The Neos Content Repository does not automatically propagate changes from a base workspace (e.g. `live`) to derived workspaces. This means the MCP workspace can become stale: nodes deleted or modified in `live` remain visible in `llm-review` until an explicit rebase occurs.

To keep the LLM's view fresh, the MCP server rebases the workspace **before every tool call**. This ensures reads reflect the latest live state and writes don't target nodes that no longer exist.

### Conflict handling

If unpublished changes in the MCP workspace conflict with live (e.g. a node was edited in the workspace but deleted in live), the rebase fails. When this happens, the tool call still executes against the stale workspace, but the response includes a conflict warning with details about which nodes are affected. The LLM can then decide to discard conflicting changes via `discardWorkspaceChanges` or inform the user.

### Rebase performance (1000 live nodes, MariaDB, 10 runs)

| Scenario | Avg | Min | Max |
|----------|-----|-----|-----|
| No-op (workspace already up-to-date) | 4.6 ms | 3.6 ms | 5.8 ms |
| Empty workspace (outdated, no unpublished changes) | 41.2 ms | 36.5 ms | 51.8 ms |
| 10 unpublished changes | 285.7 ms | 264.1 ms | 366.1 ms |
| 50 unpublished changes | 1223.9 ms | 1158.9 ms | 1320.7 ms |

The common case (no-op) adds ~5 ms per tool call. The empty-but-outdated case (typical after publishing) adds ~40 ms. Workspaces with many unpublished changes are more expensive but uncommon in normal MCP usage.

## Architecture

Production code uses a `ContentRepositoryFacade` interface (instead of the final `ContentRepository` class directly) to allow unit testing via mocks. `DefaultContentRepositoryFacade` wraps the real CR and is wired up in `McpCommandController`.

```
Classes/
  Command/McpCommandController.php   # CLI entry point, creates facade + tool provider
  ContentRepositoryFacade.php        # Interface for testability
  DefaultContentRepositoryFacade.php # Wraps real ContentRepository
  McpToolProvider.php                # Dispatches MCP tool calls to services
  Service/
    NodeReadService.php              # Read operations (find, get, children)
    NodeTypeService.php              # Node type listing and schema
    NodeWriteService.php             # Write operations (create, update, move, remove)
```

## Development

All commands run from the package directory (`DistributionPackages/GesagtGetan.NeosMcp/`). Requires [Just](https://just.systems/) >= 1.38.0.

```bash
just check            # Run all static analysis (phpcs + php-cs-fixer + phpstan)
just fix              # Auto-fix code style issues
just test             # Run all tests (unit + functional)
just test-unit        # Run unit tests only
just test-functional  # Run functional tests only
```

### Required Dev Dependencies

The package ships config files but not the tools themselves. The host project must provide these as Composer dev dependencies:

- `phpunit/phpunit` ^10.5
- `phpstan/phpstan` with `phpstan-phpunit` and `phpstan-strict-rules`
- `squizlabs/php_codesniffer`
- `friendsofphp/php-cs-fixer`

The configs (`phpcs.xml.dist`, `.php-cs-fixer.dist.php`, `phpstan.neon.dist`) use the same rules as this host project (PSR-12, @Symfony, PHPStan level max).

### Functional Test Prerequisites

Functional tests need a MySQL/MariaDB test database (configured in the host project's `Configuration/Testing/Settings.yaml`). Before the first run, create the Neos/Flow schema:

```bash
FLOW_CONTEXT=Testing ./flow doctrine:migrate
```

This only needs to be done once (or after adding new Doctrine migrations). The Content Repository's own tables (event store, projections) are created automatically by the test base class.

### FAQ

**Why two PHPUnit configs?** Flow ships a global `FunctionalTests.xml` in `Build/BuildEssentials/PhpUnit/`, but it uses the PHPUnit 9 XML schema. This project runs PHPUnit 10, which changes `<exclude>` handling and causes symlinked packages to be discovered twice. Our own `phpunit-functional.xml.dist` uses the PHPUnit 10 schema and scans only this package, avoiding the double execution and deprecation warnings.

**Why not SQLite?** The Neos Content Repository's DoctrineDbal adapter uses MySQL-specific SQL (e.g. `INSERT IGNORE`) that SQLite does not support. A real MySQL/MariaDB database is required.

### Tip: Connect the MCP server while developing

When working on this package with an MCP-capable coding agent (e.g. Claude Code), connect it to a running instance of the MCP server — locally via stdio or remotely via the HTTP transport — so it can query actual nodes, inspect workspace state, and verify tool behavior against real data.
