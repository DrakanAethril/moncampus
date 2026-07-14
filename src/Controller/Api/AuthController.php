<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Route target for POST /api/login - a route is needed so the router dispatches the request at
 * all, but App\Security\ApiLdapAuthenticator (registered on the api_login firewall, config/
 * packages/security.yaml) always intercepts authentication before this action body runs, on both
 * success (returns the JWT) and failure (returns a 401) - see its onAuthenticationSuccess/Failure.
 * This body only exists as an unreachable fallback for a route Symfony requires a controller for.
 */
class AuthController extends AbstractController
{
    #[Route(path: '/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['error' => 'invalid_credentials'], 401);
    }
}
