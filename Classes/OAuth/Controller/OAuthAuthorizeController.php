<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Controller;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthUser;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Authorization endpoint — validates the OAuth request, shows consent screen,
 * and completes the authorization with a redirect containing the auth code.
 *
 * GET /neos/mcp (with response_type=code) → authorize (requires Neos session)
 * POST /neos/mcp/grant → grant (processes consent form)
 */
class OAuthAuthorizeController extends ActionController
{
    private const CSRF_SESSION_KEY = 'GesagtGetan.NeosMcp:OAuthCsrfToken';

    #[Flow\Inject]
    protected OAuthServerFactory $oauthServerFactory;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected SessionInterface $session;

    /**
     * GET /neos/mcp?response_type=code&client_id=…&redirect_uri=…&code_challenge=…&code_challenge_method=S256&state=….
     *
     * Requires Neos session (McpUser role). Auto-grants for the configured client,
     * shows consent screen for any other client.
     */
    public function authorizeAction(): ResponseInterface
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return $this->jsonResponse(404, ['error' => 'Not found']);
        }

        $account = $this->securityContext->getAccount();
        if ($account === null) {
            return $this->jsonResponse(401, ['error' => 'Authentication required']);
        }

        $httpRequest = $this->request->getHttpRequest();
        $psrRequest = new ServerRequest(
            method: 'GET',
            uri: (string) $httpRequest->getUri(),
            headers: $httpRequest->getHeaders(),
            serverParams: $_SERVER,
        );
        $psrRequest = $psrRequest->withQueryParams($httpRequest->getQueryParams());

        $server = $this->oauthServerFactory->createAuthorizationServer();

        try {
            $authRequest = $server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse(new Response());
        }

        $authRequest->setUser(new OAuthUser($account->getAccountIdentifier()));

        $isAutoGrant = $authRequest->getClient()->getIdentifier() === $this->oauthServerFactory->getConfiguredClientId();

        // Render consent screen (or auto-submitting form for auto-grant).
        // Authorization always completes via POST to /neos/mcp/grant because Flow
        // blocks database writes during GET requests ("safe request" protection).
        return $this->renderConsentScreen($authRequest, $account->getAccountIdentifier(), $isAutoGrant);
    }

    /**
     * POST /neos/mcp/grant — processes consent form submission.
     */
    public function grantAction(): ResponseInterface
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return $this->jsonResponse(404, ['error' => 'Not found']);
        }

        $account = $this->securityContext->getAccount();
        if ($account === null) {
            return $this->jsonResponse(401, ['error' => 'Authentication required']);
        }

        $httpRequest = $this->request->getHttpRequest();
        $body = (string) $httpRequest->getBody();
        parse_str($body, $parsedBody);

        // Validate CSRF token.
        $submittedToken = $parsedBody['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !$this->validateCsrfToken($submittedToken)) {
            return $this->jsonResponse(403, ['error' => 'Invalid CSRF token']);
        }

        $approved = ($parsedBody['approve'] ?? '') === '1';

        // Reconstruct the authorization request from the hidden form fields.
        $queryParams = [];
        foreach (['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'] as $param) {
            if (isset($parsedBody[$param]) && $parsedBody[$param] !== '') {
                $queryParams[$param] = $parsedBody[$param];
            }
        }

        $psrRequest = new ServerRequest(
            method: 'GET',
            uri: (string) $httpRequest->getUri(),
            headers: $httpRequest->getHeaders(),
            serverParams: $_SERVER,
        );
        $psrRequest = $psrRequest->withQueryParams($queryParams);

        $server = $this->oauthServerFactory->createAuthorizationServer();

        try {
            $authRequest = $server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse(new Response());
        }

        $authRequest->setUser(new OAuthUser($account->getAccountIdentifier()));

        return $this->completeAuthorization($server, $authRequest, $approved);
    }

    private function completeAuthorization(
        \League\OAuth2\Server\AuthorizationServer $server,
        AuthorizationRequest $authRequest,
        bool $approved,
    ): ResponseInterface {
        $authRequest->setAuthorizationApproved($approved);

        try {
            return $server->completeAuthorizationRequest($authRequest, new Response());
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse(new Response());
        }
    }

    private function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->putData(self::CSRF_SESSION_KEY, $token);

        return $token;
    }

    private function validateCsrfToken(string $submitted): bool
    {
        $stored = $this->session->getData(self::CSRF_SESSION_KEY);
        $this->session->putData(self::CSRF_SESSION_KEY, null);

        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    private function renderConsentScreen(AuthorizationRequest $authRequest, string $accountIdentifier, bool $autoGrant = false): ResponseInterface
    {
        $clientName = htmlspecialchars($authRequest->getClient()->getName(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedAccount = htmlspecialchars($accountIdentifier, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $scopeIdentifiers = array_map(
            static fn ($s) => $s->getIdentifier(),
            $authRequest->getScopes(),
        );
        $scopeValue = implode(' ', $scopeIdentifiers);
        $scopeDisplay = implode(', ', array_map(
            static fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $scopeIdentifiers,
        ));

        $csrfToken = $this->generateCsrfToken();
        $flowCsrfToken = $this->securityContext->getCsrfProtectionToken();

        $hiddenFields = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        $hiddenFields .= '<input type="hidden" name="__csrfToken" value="' . htmlspecialchars($flowCsrfToken, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        $params = [
            'response_type' => $authRequest->getGrantTypeId() === 'authorization_code' ? 'code' : '',
            'client_id' => $authRequest->getClient()->getIdentifier(),
            'redirect_uri' => $authRequest->getRedirectUri() ?? '',
            'state' => $authRequest->getState() ?? '',
            'code_challenge' => $authRequest->getCodeChallenge() ?? '',
            'code_challenge_method' => $authRequest->getCodeChallengeMethod() ?? '',
            'scope' => $scopeValue,
        ];

        foreach ($params as $name => $value) {
            $escapedValue = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $hiddenFields .= '<input type="hidden" name="' . $name . '" value="' . $escapedValue . '">';
        }

        $autoSubmitScript = $autoGrant
            ? '<script>document.getElementById("consent-form").submit();</script>'
            : '';
        $formStyle = $autoGrant ? ' style="display:none"' : '';
        $loadingMessage = $autoGrant ? '<p>Authorizing, please wait…</p>' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize Application</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 480px; margin: 80px auto; padding: 0 20px; color: #1a1a1a; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .client-name { font-weight: 600; }
        .scopes { background: #f5f5f5; padding: 12px 16px; border-radius: 6px; margin: 16px 0; }
        .account { color: #666; font-size: 0.9rem; margin-bottom: 24px; }
        .actions { display: flex; gap: 12px; }
        button { padding: 10px 24px; border-radius: 6px; font-size: 1rem; cursor: pointer; border: 1px solid #ccc; }
        .approve { background: #0066cc; color: white; border-color: #0066cc; }
        .deny { background: white; }
    </style>
</head>
<body>
    {$loadingMessage}
    <div{$formStyle}>
        <h1>Authorize <span class="client-name">{$clientName}</span></h1>
        <p class="account">Signed in as {$escapedAccount}</p>
        <p>This application is requesting access to:</p>
        <div class="scopes">{$scopeDisplay}</div>
    </div>
    <form id="consent-form" method="POST" action="/neos/mcp/grant"{$formStyle}>
        {$hiddenFields}
        <input type="hidden" name="approve" value="1">
        <div class="actions"{$formStyle}>
            <button type="submit" name="approve" value="1" class="approve">Authorize</button>
            <button type="submit" name="approve" value="0" class="deny">Deny</button>
        </div>
    </form>
    {$autoSubmitScript}
</body>
</html>
HTML;

        return new Response(
            status: 200,
            headers: [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; frame-ancestors 'none'",
            ],
            body: $html,
        );
    }

    /** @param array<mixed> $data */
    private function jsonResponse(int $statusCode, array $data): ResponseInterface
    {
        return new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
