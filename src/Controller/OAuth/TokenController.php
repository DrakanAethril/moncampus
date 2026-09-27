<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\OAuth\OAuthException;
use App\OAuth\TokenIssuer;
use App\Repository\OAuthClientRepository;
use App\Service\PostValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The token endpoint of the connector: a code, or a refresh token, exchanged for a new pair.
 *
 * Form-encoded as RFC 6749 wants it (claude.ai sends nothing else), public clients only - the
 * client names itself with `client_id`, and PKCE or the rotation of the refresh token is what
 * proves it. Every answer, success or error, carries `Cache-Control: no-store`.
 */
class TokenController extends AbstractController
{
    #[Route(path: '/oauth/token', name: 'app_oauth_token', methods: ['POST'])]
    public function token(Request $request, OAuthClientRepository $clients, TokenIssuer $issuer): JsonResponse
    {
        try {
            // A public client usually names itself in the body; some send HTTP Basic with an empty
            // password instead, which says the same thing.
            $clientId = PostValue::trimmed($request, 'client_id', (string) $request->getUser());
            $client = $clients->findOneByClientId($clientId) ?? throw OAuthException::invalidClient('Unknown client_id.');

            $pair = match (PostValue::string($request, 'grant_type')) {
                'authorization_code' => $issuer->exchangeCode(
                    $client,
                    PostValue::string($request, 'code'),
                    PostValue::string($request, 'redirect_uri'),
                    PostValue::string($request, 'code_verifier'),
                    '' === PostValue::string($request, 'resource') ? null : PostValue::string($request, 'resource'),
                ),
                'refresh_token' => $issuer->refresh($client, PostValue::string($request, 'refresh_token')),
                default => throw new OAuthException('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.'),
            };

            return $this->noStore(new JsonResponse($pair->toArray()));
        } catch (OAuthException $exception) {
            return $this->noStore(new JsonResponse($exception->toArray(), $exception->status));
        }
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
