# GesagtGetan.NeosMcp

MCP (Model Context Protocol) server for the Neos 9 Content Repository. Gives LLMs structured access to content nodes, node types, and workspaces.

## HTTP Transport (OAuth)

The package exposes the MCP server over HTTP at `POST /api/mcp`, secured with OAuth 2.0 (authorization code grant + PKCE). This is used by Claude.ai's remote MCP connector and ChatGPT.

Each authenticated user has their own personal workspace (the same one they use in the Neos UI). Changes are isolated per user and can be reviewed/published independently. The JWT `sub` claim contains the Neos `UserId` (UUID), not the username — no credentials are leaked in tokens.

### Setup

1. Generate client credentials (or retrieve from password manager if they exist already)
   ```bash
   openssl rand -hex 16   # client_id
   openssl rand -hex 32   # client_secret
   ```
2. Enter them in the Claude.ai or ChatGPT connector's settings along with the MCP endpoint URL: `https://your-domain.com/api/mcp`.
3. Configure `Configuration/Production/Settings.yaml`:
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

4. Run `./flow mcp:setup` to create the stdio workspace, register the OAuth client, and generate RSA keys:
   ```bash
   ./flow mcp:setup
   ```

   > ⚠️ Re-run `mcp:setup` whenever you change client credentials, redirect URIs, or any other `oauth.client.*` setting. The database is not updated automatically.

5. Assign the `GesagtGetan.NeosMcp:McpUser` role to Neos accounts that should be able to authorize MCP access.

6. Ensure the following endpoints are publicly accessible (no basic auth, no firewall restrictions): `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`, `/oauth/token`, `/api/mcp`. If your server uses basic auth or IP restrictions, exempt these routes. The authorization endpoint (`GET /api/mcp`) is also exempted but requires a Neos session, so there is no security gap.

   For example, to bypass basic auth on staging domains, add this to `Web/.htaccess`:
   ```apache
   # Bypass basic auth for OAuth/MCP endpoints so Claude can reach them without credentials.
   <If "(%{HTTP_HOST} =~ /\.proserver\.punkt\.de/ || %{HTTP_HOST} =~ /\.getan\.at/) && %{THE_REQUEST} !~ m#(GET|POST|OPTIONS) /(\.well-known/oauth-|oauth/token|api/mcp)#">
   ```

7. Apache with `mod_proxy_fcgi` strips the `Authorization` header before it reaches PHP, causing all bearer token requests to fail silently with `401`. Add the following lines to `Web/.htaccess` inside the `<IfModule mod_rewrite.c>` block, right after `RewriteBase /`:
   ```apache
   # Forward the Authorization header to PHP — Apache mod_proxy_fcgi strips it otherwise.
   RewriteCond %{HTTP:Authorization} .
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```
   This copies the `Authorization` header into the `HTTP_AUTHORIZATION` environment variable so PHP can read it. The `RewriteCond` ensures it only fires when the header is present.

8. Connect the MCP server in Claude.ai or ChatGPT and test with a prompt like "List all node types for the connected Neos website" to verify the connection works end-to-end.

## CLI Transport (stdio, optional)

Optional transport for local development. Useful for testing tools directly or connecting a local coding agent (e.g. Claude Code) without going through OAuth.

### Setup

```bash
./flow mcp:setup
```

This creates the shared stdio workspace, generates OAuth RSA keys, and registers the OAuth client in the database. Run it during initial setup and **after every configuration change** (credentials, redirect URIs, etc.) to apply the new values.

### Usage

```bash
./flow mcp:server
```

Flow command that reads MCP requests from stdin and writes responses to stdout. Requires the stdio workspace to exist — run `mcp:setup` first. Assign the `GesagtGetan.NeosMcp:McpUser` role to Neos accounts that need to see and manage the MCP workspace in the Neos UI.

### Claude Code Configuration 

Add to `.mcp.json` in your project root:

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

Then run `/mcp` in Claude Code to connect the server.

### Optional Workspace Configuration

In `Settings.yaml`:

```yaml
GesagtGetan:
  NeosMcp:
    contentRepositoryId: 'default'
    stdioWorkspaceName: 'llm-review'
    stdioWorkspaceTitle: 'MCP Stdio'
    stdioWorkspaceDescription: 'Shared workspace for MCP stdio transport'
    stdioBaseWorkspaceName: 'live'
```

## Available Tools

### Read

- `getContentRepositoryInfo` — dimensions, workspaces, dimension space points
- `listNodeTypes` — list non-abstract node types (optional filter)
- `getNodeTypeSchema` — full schema for a node type including properties, child nodes, and references
- `findNodes` — search by type and/or search term
- `getNode` — get a single node with all properties
- `getChildren` — list child nodes, optionally filtered by type
- `getWorkspaceStatus` — workspace status including pending change count

### Write (staged in workspace, requires human publishing)

- `createNode` — create a node under a parent (ID auto-generated)
- `setNodeProperties` — partial property update
- `moveNode` — move to new parent
- `hideNode` — hide a node from the public site (reversible)
- `unhideNode` — unhide a previously hidden node
- `findAndReplace` — batch find/replace across the content tree
- `removeNode` — soft-delete a node (can be restored)

### Workspace

- `discardWorkspaceChanges` — discard all pending changes

## Workspace Rebase

The Neos Content Repository does not automatically propagate changes from a base workspace (e.g. `live`) to derived workspaces. This means the MCP workspace can become stale: nodes deleted or modified in `live` remain visible in the workspace until an explicit rebase occurs.

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

### Dev Dependencies

Dev tools are declared in `require-dev` in this package's `composer.json`.

### Functional Test Prerequisites

Functional tests need a MySQL/MariaDB test database (configured in the host project's `Configuration/Testing/Settings.yaml`). The Content Repository's own tables (event store, projections) are created automatically by the test base class.

### FAQ

**Why two PHPUnit configs?** Flow ships a global `FunctionalTests.xml` in `Build/BuildEssentials/PhpUnit/`, but it uses the PHPUnit 9 XML schema. This project runs PHPUnit 10, which changes `<exclude>` handling and causes symlinked packages to be discovered twice. Our own `phpunit-functional.xml.dist` uses the PHPUnit 10 schema and scans only this package, avoiding the double execution and deprecation warnings.

**Why not SQLite?** The Neos Content Repository's DoctrineDbal adapter uses MySQL-specific SQL (e.g. `INSERT IGNORE`) that SQLite does not support. A real MySQL/MariaDB database is required.

### Tip: Connect the MCP server while developing

When working on this package with an MCP-capable coding agent (e.g. Claude Code), connect it to a running instance of the MCP server — locally via stdio or remotely via the HTTP transport — so it can query actual nodes, inspect workspace state, and verify tool behavior against real data. (After local code changes, reconnect the MCP server — e.g. via `/mcp` in Claude Code — so the agent picks up the updated PHP files.)
