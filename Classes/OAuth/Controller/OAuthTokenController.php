<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Controller;

use GesagtGetan\NeosMcp\OAuth\Exception\OAuthServerException as McpOAuthServerException;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;

/**
 * Token endpoint — delegates to league's AuthorizationServer.
 */
class OAuthTokenController extends ActionController
{
    /** @phpstan-var array<string> */
    protected $supportedMediaTypes = ['application/json']; // @phpstan-ignore property.phpDocType

    #[Flow\Inject]
    protected OAuthServerFactory $oauthServerFactory;

    public function preflightAction(): ResponseInterface
    {
        return new Response(204, $this->corsHeaders());
    }

    public function tokenAction(): ResponseInterface
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return $this->jsonResponse(503, ['error' => 'MCP HTTP transport is disabled. Set GesagtGetan.NeosMcp.oauth.enabled to true in Settings.yaml and run ./flow mcp:setup.']);
        }

        $httpRequest = $this->request->getHttpRequest();

        // League expects a PSR-7 ServerRequestInterface with parsed body.
        $body = (string) $httpRequest->getBody();
        parse_str($body, $parsedBody);

        $psrRequest = new ServerRequest(
            method: 'POST',
            uri: (string) $httpRequest->getUri(),
            headers: $httpRequest->getHeaders(),
            body: $body,
            serverParams: $_SERVER,
        );
        $psrRequest = $psrRequest->withParsedBody($parsedBody);

        $server = $this->oauthServerFactory->createAuthorizationServer();
        $psrResponse = new Response(200, $this->corsHeaders());

        try {
            $response = $server->respondToAccessTokenRequest($psrRequest, $psrResponse);
            // League writes the JSON body via $stream->write(), which advances the
            // pointer to the end. Without rewind, Flow's emitter reads an empty
            // body and clients receive Content-Length: 0.
            $response->getBody()->rewind();

            return $response;
        } catch (OAuthServerException $e) {
            throw new McpOAuthServerException('OAuth token exchange failed: ' . $e->getMessage() . ($e->getHint() !== null ? ' (' . $e->getHint() . ')' : ''), 1740000022, $e);
        }
    }

    /** @return array<string, string> */
    private function corsHeaders(): array
    {
        $origin = $this->request->getHttpRequest()->getHeaderLine('Origin');
        $allowed = $this->oauthServerFactory->getCorsAllowedOrigin($origin);

        if ($allowed === null) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $allowed,
            'Access-Control-Allow-Methods' => 'POST',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ];
    }

    /** @param array<mixed> $data */
    private function jsonResponse(int $statusCode, array $data): ResponseInterface
    {
        return new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'] + $this->corsHeaders(),
            body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
