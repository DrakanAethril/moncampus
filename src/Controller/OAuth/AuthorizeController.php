<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Enum\ExternalService;
use App\Enum\ExternalServiceSignIn;
use App\Enum\PlatformActivityType;
use App\OAuth\ConnectorScope;
use App\OAuth\ConnectorUrls;
use App\OAuth\Pkce;
use App\OAuth\RedirectUriPolicy;
use App\OAuth\TokenIssuer;
use App\Repository\OAuthClientRepository;
use App\Repository\UserRepository;
use App\Security\ExternalServicePasswords;
use App\Security\FeatureAccess;
use App\Security\PlatformPasswordCheckUnavailable;
use App\Service\PlatformActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The consent screen of the Claude connector - the one moment a person, and not a client, decides.
 *
 * **It never asks for the establishment password.** The person signs in here with the password they
 * chose for this service in « Mon profil » (App\Security\ExternalServicePasswords) - the directory
 * password opens the establishment's internal resources and is not typed, nor relied on, to open a
 * service outside it. So the screen is public (`access_control`), sits on `main` only to read a
 * session when there is one - which then fixes the identifier - and a person who has not chosen a
 * service password yet cannot authorise at all: the screen sends them to their profile first.
 *
 * The request is checked twice, on display and on submission, because the form carries it back in
 * hidden fields and a hidden field is only what the browser chose to send.
 *
 * Two kinds of refusal, and the order matters (RFC 6749 § 4.1.2.1): as long as the client and its
 * redirect URI are not both established, the error is shown **here** - sending it to an unverified
 * address would make this screen an open redirector. Once they are, errors go back to the client,
 * which is the only party able to act on them.
 *
 * No #[RequiresFeature] on the class: the visitor may not be signed in, and the feature can only be
 * asked of the person the service password identifies - which it is, right after.
 */
class AuthorizeController extends AbstractController
{
    private const ExternalService SERVICE = ExternalService::ClaudeConnector;

    /** The authorisation request a person left to choose their service password, to be resumed. */
    public const string RETURN_SESSION_KEY = 'oauth_authorize_return';

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
        ExternalServicePasswords $servicePasswords,
        UserRepository $users,
        FeatureAccess $featureAccess,
        #[Target('external_service_sign_in')] RateLimiterFactoryInterface $limiter,
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

        $viewer = $this->getUser();
        $sessionUser = $viewer instanceof User ? $viewer : null;
        $screen = fn (?string $error = null, int $status = Response::HTTP_OK, string $username = ''): Response => $this->render('oauth/authorize.html.twig', [
            'client' => $client,
            'redirectHost' => parse_url($redirectUri, \PHP_URL_HOST),
            'authorization' => $authorization,
            'sessionUser' => $sessionUser,
            // Somebody signed in who never chose a password for this service: there is nothing to
            // type yet, only a profile to go to.
            'needsServicePassword' => null !== $sessionUser && null === $servicePasswords->find($sessionUser, self::SERVICE),
            'username' => $sessionUser?->getUserIdentifier() ?? $username,
            'error' => $error,
        ], new Response('', $status));

        if (!$request->isMethod('POST')) {
            if (null !== $sessionUser && null === $servicePasswords->find($sessionUser, self::SERVICE)) {
                // Where « Mon profil » sends them back once the password is chosen - kept on the
                // server, so the profile can only ever return to an authorisation request.
                $request->getSession()->set(self::RETURN_SESSION_KEY, $request->getUri());
            }

            return $screen();
        }

        if (!$this->isCsrfTokenValid('oauth_authorize', $this->parameter($parameters, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ('allow' !== $this->parameter($parameters, 'decision')) {
            return $back('access_denied');
        }

        // A signed-in visitor authorises for themselves; the identifier field is only read from
        // somebody who is not.
        $username = $sessionUser?->getUserIdentifier() ?? $this->parameter($parameters, 'username');

        foreach (['ip:'.$request->getClientIp(), 'user:'.mb_strtolower($username)] as $key) {
            if (!$limiter->create($key)->consume()->isAccepted()) {
                return $screen('externalServiceSignInTooManyAttemptsMessage', Response::HTTP_TOO_MANY_REQUESTS, $username);
            }
        }

        $user = '' === $username ? null : $users->findOneBy(['username' => $username]);

        try {
            $signIn = $servicePasswords->check($user, self::SERVICE, (string) $request->request->get('servicePassword', ''));
        } catch (PlatformPasswordCheckUnavailable) {
            return $screen('externalServicePasswordUncheckableMessage', Response::HTTP_SERVICE_UNAVAILABLE, $username);
        }

        if (ExternalServiceSignIn::SameAsPlatformPassword === $signIn) {
            return $screen('externalServicePasswordBecamePlatformMessage', Response::HTTP_UNAUTHORIZED, $username);
        }

        // Wrong password, unknown or deactivated account, no service password chosen: one answer
        // for all four, so the screen tells nobody which accounts exist or have a password.
        if (ExternalServiceSignIn::Accepted !== $signIn || !$user instanceof User) {
            return $screen('externalServiceSignInRefusedMessage', Response::HTTP_UNAUTHORIZED, $username);
        }

        if (!$featureAccess->isEnabled(self::SERVICE->feature(), $user)) {
            return $screen('externalServiceNotOpenMessage', Response::HTTP_FORBIDDEN, $username);
        }

        $grant = new OAuthGrant($user, $client, $scope, $clock->now());
        $entityManager->persist($grant);
        $code = $issuer->issueCode($grant, $redirectUri, $challenge, '' === $resource ? null : $resource);

        $activity->record(PlatformActivityType::ClaudeConnectorAuthorized, $user, $request, [
            'grant' => (string) $grant->getId(),
            'signIn' => 'service_password',
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
