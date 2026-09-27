<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Entity\OAuthClient;
use App\OAuth\RedirectUriPolicy;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dynamic client registration (RFC 7591) - how claude.ai and Claude Code introduce themselves to
 * the connector without anybody pasting a client id.
 *
 * Open to the internet, as the specification requires, and harmless for it: a registered client is
 * a name and a list of redirect URIs drawn from a closed list (App\OAuth\RedirectUriPolicy). It opens
 * nothing until a user consents, and the consent screen shows the redirect host it would send the
 * code to. Clients nobody ever consented to are purged after a month.
 */
class RegistrationController extends AbstractController
{
    #[Route(path: '/oauth/register', name: 'app_oauth_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        RedirectUriPolicy $redirectUris,
        #[Target('oauth_client_registration')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if (!$limiter->create('ip:'.$request->getClientIp())->consume()->isAccepted()) {
            return $this->refuse('slow_down', 'Too many registrations from this address.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $metadata = JsonRequestPayload::fromRequest($request);
        $uris = $metadata->strings('redirect_uris');

        if ([] === $uris) {
            return $this->refuse('invalid_redirect_uri', 'redirect_uris is required.');
        }

        foreach ($uris as $uri) {
            if (!$redirectUris->isRegistrable($uri)) {
                return $this->refuse('invalid_redirect_uri', \sprintf('Redirect URI not accepted by this server: %s', $uri));
            }
        }

        $authMethod = $metadata->string('token_endpoint_auth_method', 'none');
        if ('none' !== $authMethod) {
            return $this->refuse('invalid_client_metadata', 'Only public clients (token_endpoint_auth_method "none") are supported.');
        }

        $name = mb_substr(trim($metadata->string('client_name')), 0, 200);
        $client = new OAuthClient(
            'mcc_'.bin2hex(random_bytes(16)),
            '' === $name ? 'Client MCP' : $name,
            array_values(array_unique($uris)),
            $request->getClientIp(),
        );
        $entityManager->persist($client);
        $entityManager->flush();

        $response = new JsonResponse([
            'client_id' => $client->getClientId(),
            'client_id_issued_at' => $client->getCreatedAt()->getTimestamp(),
            'client_name' => $client->getClientName(),
            'redirect_uris' => $client->getRedirectUris(),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], Response::HTTP_CREATED);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function refuse(string $error, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'error_description' => $description], $status);
    }
}
