# OAuth 2.0 Authorization Server (`Classes/OAuth/`)

Built on `league/oauth2-server` ^8.5. Implements the OAuth 2.0 authorization code grant with PKCE for Claude's remote MCP connector requirements.

**Flow**: Claude discovers endpoints via `.well-known` metadata → user authorizes in browser (Neos session) → Claude exchanges auth code for JWT access token → JWT validated on each MCP request. Client is pre-registered via Settings.yaml (no Dynamic Client Registration).

| Layer | Classes | Notes |
|-------|---------|-------|
| Entities | `OAuthClient`, `OAuthAuthCode`, `OAuthRefreshToken` (Flow entities), `OAuthAccessToken` (in-memory, JWT), `OAuthScope`, `OAuthUser` (value objects) | All use `#[Flow\Proxy(false)]`; DB entities have explicit `@ORM\Id` since Flow doesn't inject PK on unproxied classes |
| Repositories | `OAuthClientRepository`, `OAuthAuthCodeRepository`, `OAuthRefreshTokenRepository` (Flow repos), `OAuthAccessTokenRepository` (no-op), `OAuthScopeRepository` (hardcoded "mcp") | Implement league's repository interfaces |
| Service | `OAuthServerFactory` — creates league's `AuthorizationServer` + `ResourceServer`, auto-generates RSA keys | Keys stored in `Data/Persistent/GesagtGetan.NeosMcp/` |
| Controllers | `OAuthMetadataController` (.well-known), `OAuthAuthorizeController` (consent), `OAuthTokenController` (token exchange) | All under `OAuth\Controller` subpackage |

**Configuration** (`Settings.yaml`): `GesagtGetan.NeosMcp.oauth.enabled` (default false), `.issuer`, `.client.id`, `.client.secret`, `.client.knownRedirectUris`, `.accessTokenLifetime`.

**Security** (`Policy.yaml`): `McpUser` role (extends `AbstractEditor`) required for authorization endpoint. All other OAuth endpoints are public (Everybody).

**PKCE**: required from every client, including the confidential configured one. League only enforces it for public clients, so `OAuthAuthorizeController` rejects requests without `code_challenge` itself (`invalid_request`, redirected to the client when the redirect URI is known).

**Error responses**: league's `OAuthServerException` is turned into the RFC 6749 response, never into a Flow exception page. The token endpoint returns the JSON error body (e.g. `{"error":"invalid_grant"}`), the authorize endpoint redirects to the client with `error=` when the redirect URI has been verified and otherwise shows an HTML page with the diagnostics.

**Endpoints** (`Routes.yaml`): `GET /.well-known/oauth-protected-resource/api/mcp` (RFC 9728, path-suffixed for the resource `/api/mcp`; also the `resource_metadata` pointer in the `WWW-Authenticate` challenge), `GET /.well-known/oauth-authorization-server` (RFC 8414), `GET /oauth/authorize` (consent, Neos session), `POST /oauth/grant` (consent form target), `POST /oauth/token`, `POST /api/mcp` (MCP transport; `GET` and `DELETE` answer 405, as the Streamable HTTP transport requires from a server without SSE stream or sessions).

**Staging basic auth** (`Web/.htaccess`): If your environment puts the site behind HTTP basic auth, the OAuth/MCP routes (`/.well-known/oauth-*`, `/oauth/authorize`, `/oauth/grant`, `/oauth/token`, `/api/mcp`) must be exempted so Claude can reach them without credentials. The authorization endpoint (`GET /oauth/authorize`) and the consent form (`POST /oauth/grant`) are exempted too but require a Neos session, so there is no security gap. Deployments upgrading from the old `GET /api/mcp` authorization endpoint must add the two `/oauth/*` paths to their exemptions.
