# OAuth 2.0 Authorization Server (`Classes/OAuth/`)

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
