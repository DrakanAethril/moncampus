<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Attribute\RequiresFeature;
use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\PlatformActivityType;
use App\OAuth\ConnectorScope;
use App\OAuth\ConnectorUrls;
use App\OAuth\Pkce;
use App\OAuth\RedirectUriPolicy;
use App\OAuth\TokenIssuer;
use App\Repository\OAuthClientRepository;
use App\Service\PlatformActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The consent screen of the Claude connector - the one moment a person, and not a client, decides.
 *
 * It sits on the ordinary `main` firewall: somebody not signed in is sent through the LDAP login or
 * the magic link and brought back here (the target path), so the connector never sees a password.
 * The request is checked twice, on display and on submission, because the form carries it back in
 * hidden fields and a hidden field is only what the browser chose to send.
 *
 * Two kinds of refusal, and the order matters (RFC 6749 § 4.1.2.1): as long as the client and its
 * redirect URI are not both established, the error is shown **here** - sending it to an unverified
 * address would make this screen an open redirector. Once they are, errors go back to the client,
 * which is the only party able to act on them.
 */
#[RequiresFeature(Feature::ClaudeConnector)]
class AuthorizeController extends AbstractController
{
    #[Route(path: '/oauth/authorize', name: 'app_oauth_authorize', methods: ['GET', 'POST'])]
    public function authorize(
        Request $request,
        OAuthClientRepository $clients,
        RedirectUriPolicy $redirectUris,
        ConnectorUrls $urls,
        TokenIssuer $issuer,
        EntityManagerInterface $entityManager,
        PlatformActivityRecorder $activity,
        ClockInterface $clock,
    ): Response {
        $parameters = $request->isMethod('POST') ? $request->request : $request->query;
        $client = $clients->findOneByClientId($this->parameter($parameters, 'client_id'));
        $redirectUri = $this->parameter($parameters, 'redirect_uri');

        if (!$client instanceof OAuthClient || '' === $redirectUri || !$redirectUris->matches($client->getRedirectUris(), $redirectUri)) {
            return $this->render('oauth/authorize_error.html.twig', [], new Response('', Response::HTTP_BAD_REQUEST));
        }

        $state = $this->parameter($parameters, 'state');
        $back = fn (string $error): RedirectResponse => $this->backToClient($redirectUri, ['error' => $error, 'state' => $state, 'iss' => $urls->issuer()]);

        if ('code' !== $this->parameter($parameters, 'response_type')) {
            return $back('unsupported_response_type');
        }

        $challenge = $this->parameter($parameters, 'code_challenge');
        if (Pkce::METHOD !== $this->parameter($parameters, 'code_challenge_method') || !Pkce::isValidChallenge($challenge)) {
            return $back('invalid_request');
        }

        $scope = ConnectorScope::normalize($this->parameter($parameters, 'scope'));
        if (null === $scope) {
            return $back('invalid_scope');
        }

        // RFC 8707: a client may name the resource it wants a token for. This server protects exactly
        // one, and a token asked for somewhere else is refused rather than issued for /mcp anyway.
        $resource = $this->parameter($parameters, 'resource');
        if ('' !== $resource && $resource !== $urls->mcpUrl()) {
            return $back('invalid_target');
        }

        $authorization = [
            'response_type' => 'code',
            'client_id' => $client->getClientId(),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => Pkce::METHOD,
            'scope' => $scope,
            'state' => $state,
            'resource' => $resource,
        ];

        if (!$request->isMethod('POST')) {
            return $this->render('oauth/authorize.html.twig', [
                'client' => $client,
                'redirectHost' => parse_url($redirectUri, \PHP_URL_HOST),
                'authorization' => $authorization,
            ]);
        }

        if (!$this->isCsrfTokenValid('oauth_authorize', $this->parameter($parameters, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ('allow' !== $this->parameter($parameters, 'decision')) {
            return $back('access_denied');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $grant = new OAuthGrant($user, $client, $scope, $clock->now());
        $entityManager->persist($grant);
        $code = $issuer->issueCode($grant, $redirectUri, $challenge, '' === $resource ? null : $resource);

        $activity->record(PlatformActivityType::ClaudeConnectorAuthorized, $user, $request, [
            'grant' => (string) $grant->getId(),
            'client' => $client->getClientName(),
            'redirectHost' => (string) parse_url($redirectUri, \PHP_URL_HOST),
        ]);

        return $this->backToClient($redirectUri, ['code' => $code, 'state' => $state, 'iss' => $urls->issuer()]);
    }

    private function parameter(InputBag $parameters, string $key): string
    {
        $value = $parameters->get($key);

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, string> $parameters
     */
    private function backToClient(string $redirectUri, array $parameters): RedirectResponse
    {
        // An empty `state` is dropped rather than sent back empty: the client did not send one.
        $query = http_build_query(array_filter($parameters, static fn (string $value): bool => '' !== $value), '', '&', \PHP_QUERY_RFC3986);

        return new RedirectResponse($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query);
    }
}
