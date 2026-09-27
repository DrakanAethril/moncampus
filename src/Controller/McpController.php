<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Enum\Feature;
use App\Mcp\McpServer;
use App\OAuth\RedirectUriPolicy;
use App\Security\McpAccessTokenAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Claude connector's single endpoint (Model Context Protocol, « Streamable HTTP » transport,
 * in its stateless form - see App\Mcp\McpServer for why).
 *
 * The caller is authenticated before it gets here, by its OAuth access token on the `mcp` firewall
 * (App\Security\McpAccessTokenAuthenticator); the feature is checked like any screen's, and an
 * account the connector is switched off for gets the 404 of an extinguished feature.
 */
#[RequiresFeature(Feature::ClaudeConnector)]
class McpController extends AbstractController
{
    #[Route(path: '/mcp', name: 'app_mcp', methods: ['POST'])]
    public function handle(Request $request, McpServer $server): Response
    {
        if (!$this->isAllowedOrigin($request)) {
            return new JsonResponse(McpServer::error(null, McpServer::INVALID_REQUEST, 'Origin not allowed.'), Response::HTTP_FORBIDDEN);
        }

        try {
            $message = json_decode($request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(McpServer::error(null, McpServer::PARSE_ERROR, 'Parse error.'), Response::HTTP_BAD_REQUEST);
        }

        // JSON-RPC batches were removed from MCP in 2025-06-18; a list is refused as a whole.
        if (!\is_array($message) || array_is_list($message)) {
            return new JsonResponse(McpServer::error(null, McpServer::INVALID_REQUEST, 'Expected a single JSON-RPC message.'), Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $grant = $request->attributes->get(McpAccessTokenAuthenticator::GRANT_ATTRIBUTE);
        $response = $server->handle($message, $user, $grant instanceof OAuthGrant ? $grant : null, $request);

        if (null === $response) {
            return new Response('', Response::HTTP_ACCEPTED);
        }

        return new JsonResponse($response, Response::HTTP_OK, [], false);
    }

    /**
     * No server-sent stream is offered, which the transport lets a server say with a 405 on GET; and
     * there is no session to end with DELETE.
     */
    #[Route(path: '/mcp', name: 'app_mcp_stream', methods: ['GET', 'DELETE'])]
    public function noStream(): Response
    {
        return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
    }

    /**
     * The transport's DNS-rebinding guard: a browser page on another site must not be able to drive
     * the endpoint with a token it got hold of. Server-to-server callers - claude.ai's backend,
     * Claude Code - send no Origin at all, and pass.
     */
    private function isAllowedOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if (null === $origin || '' === $origin) {
            return true;
        }

        $allowed = [$request->getSchemeAndHttpHost()];
        foreach (RedirectUriPolicy::HOSTED_CALLBACKS as $callback) {
            $allowed[] = (string) preg_replace('#^(https://[^/]+).*$#', '$1', $callback);
        }

        return \in_array($origin, $allowed, true);
    }
}
