<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\OAuthGrant;
use App\OAuth\AccessTokenVerifier;
use App\OAuth\ConnectorUrls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates a call of the Claude connector by its OAuth access token, on the `mcp` firewall
 * alone.
 *
 * **Authenticating rather than checking by hand is the point**, exactly as for the iCal feed
 * (App\Security\CalendarTokenAuthenticator): once the request carries the token's owner as a real
 * user, App\Security\FeatureAccess, App\Security\StructureAccessChecker and every voter answer for
 * them unchanged. A tool of the connector can do what its user can do on the screen, and nothing
 * else - there is no second copy of the rules to drift.
 *
 * It is also the firewall's entry point, because the answer to « no token » is part of the
 * protocol: a 401 whose `WWW-Authenticate` names the protected-resource metadata is how claude.ai
 * discovers where to send the teacher to sign in (RFC 9728 § 5.1). Anything else - a redirect to
 * /login, a 404 - and the client simply reports the server as broken.
 */
class McpAccessTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /** The request attribute a tool reads to know which connection it acts through. */
    public const string GRANT_ATTRIBUTE = '_oauth_grant';

    public function __construct(
        private readonly AccessTokenVerifier $verifier,
        private readonly ConnectorUrls $urls,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return null !== $this->bearerOf($request);
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->verifier->verify((string) $this->bearerOf($request));

        if (null === $token) {
            throw new CustomUserMessageAuthenticationException('The access token is invalid or has expired.');
        }

        $grant = $token->getGrant();
        $user = $grant->getUser();
        $request->attributes->set(self::GRANT_ATTRIBUTE, $grant);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $grant = $request->attributes->get(self::GRANT_ATTRIBUTE);

        if ($grant instanceof OAuthGrant && $grant->markUsed($this->clock->now(), $request->getClientIp())) {
            $this->entityManager->flush();
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->challenge('invalid_token');
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->challenge(null);
    }

    private function challenge(?string $error): Response
    {
        $parameters = null === $error ? '' : \sprintf('error="%s", ', $error);
        $response = new JsonResponse(['error' => $error ?? 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        $response->headers->set('WWW-Authenticate', \sprintf('Bearer %sresource_metadata="%s"', $parameters, $this->urls->resourceMetadataUrl()));

        return $response;
    }

    private function bearerOf(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return 1 === preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) ? $matches[1] : null;
    }
}
