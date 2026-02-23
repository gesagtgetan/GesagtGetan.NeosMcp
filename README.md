# GesagtGetan.NeosMcp

MCP (Model Context Protocol) server for the Neos 9 Content Repository. Gives LLMs structured access to content nodes, node types, and workspaces.

## Setup

Create the review workspace (once, during initial setup or deployment):

```bash
./flow mcp:setup
```

This creates a shared workspace visible in the Neos UI with proper metadata and role assignments.

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

The package also exposes the MCP server over HTTP at `POST /neos/mcp`, secured with OAuth 2.0 (authorization code grant + PKCE). This is used by Claude.ai's remote MCP connector and ChatGPT.

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

3. Store the same client_id and client_secret in your password manager. Enter them in the Claude.ai connector's Advanced settings.

4. Run the database migration to create the OAuth tables:
   ```bash
   ./flow doctrine:migrate
   ```

5. Assign the `GesagtGetan.NeosMcp:McpUser` role to Neos accounts that should be able to authorize MCP access.

6. Add `Data/Persistent/GesagtGetan.NeosMcp/` to Deployer's `shared_dirs` so the auto-generated RSA keys persist across deployments.

### Staging basic auth

The proserverXXXX or getan.at domains require HTTP basic auth via `Web/.htaccess`. The OAuth/MCP routes (`/.well-known/oauth-*`, `/oauth/token`, `/neos/mcp`) are exempted so Claude can reach them without basic auth credentials. The authorization endpoint (`GET /neos/mcp`) is also exempted but requires a Neos session, so there is no security gap.

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

### Write (review workspace)
- `create_node` - create a node
- `set_node_properties` - update properties
- `move_node` - move to new parent
- `remove_node` - remove a node
- `find_and_replace_property` - batch find/replace in properties

### Workspace
- `get_workspace_status` - review workspace status
- `discard_workspace_changes` - discard all pending changes

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
