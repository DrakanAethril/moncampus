<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\OAuth\ConnectorScope;
use App\OAuth\ConnectorUrls;
use App\OAuth\Pkce;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two discovery documents a client of the Claude connector reads before anything else.
 *
 * Public, on the `oauth_public` firewall, and carrying no #[RequiresFeature]: they describe where
 * the doors are, not what is behind them, and a client that is told « 404 » here cannot even say
 * why the teacher's account refuses it. The feature is enforced where something opens - the consent
 * screen and /mcp itself.
 */
class MetadataController extends AbstractController
{
    public function __construct(private readonly ConnectorUrls $urls)
    {
    }

    /**
     * RFC 9728 - « which authorization server protects /mcp ». Served both at the path-suffixed
     * address the 401 points to and at the bare one clients fall back on.
     */
    #[Route(path: '/.well-known/oauth-protected-resource/mcp', name: 'app_oauth_protected_resource_metadata', methods: ['GET'])]
    #[Route(path: '/.well-known/oauth-protected-resource', name: 'app_oauth_protected_resource_metadata_root', methods: ['GET'])]
    public function protectedResource(): JsonResponse
    {
        return $this->document([
            'resource' => $this->urls->mcpUrl(),
            'authorization_servers' => [$this->urls->issuer()],
            'scopes_supported' => ConnectorScope::SUPPORTED,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'MonCampus',
        ]);
    }

    /** RFC 8414 - the endpoints, and the few choices a client has to make against them. */
    #[Route(path: '/.well-known/oauth-authorization-server', name: 'app_oauth_authorization_server_metadata', methods: ['GET'])]
    public function authorizationServer(): JsonResponse
    {
        return $this->document([
            'issuer' => $this->urls->issuer(),
            'authorization_endpoint' => $this->urls->absolute('app_oauth_authorize'),
            'token_endpoint' => $this->urls->absolute('app_oauth_token'),
            'registration_endpoint' => $this->urls->absolute('app_oauth_register'),
            'scopes_supported' => ConnectorScope::SUPPORTED,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => [Pkce::METHOD],
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /** @param array<string, mixed> $document */
    private function document(array $document): JsonResponse
    {
        $response = new JsonResponse($document);
        // Addresses and capabilities that change with a deployment, not with every request: an hour
        // of cache spares the discovery round-trips without outliving a release by much.
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
