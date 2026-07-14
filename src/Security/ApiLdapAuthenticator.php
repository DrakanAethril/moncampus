<?php

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Stateless counterpart to LdapAuthenticator, for the mobile app's POST /api/login: same LDAP
 * bind check via the shared LdapCredentialsVerifier (mobile auth must always go through LDAP too,
 * never a locally-stored password), but reads JSON credentials instead of a form post, and on
 * success returns a JWT instead of redirecting - the api/api_login firewalls (config/packages/
 * security.yaml) are both stateless, so there's no session/CSRF/remember-me involved here.
 */
class ApiLdapAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly LdapCredentialsVerifier $credentialsVerifier,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return '/api/login' === $request->getPathInfo() && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $data = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationException('Malformed request body.');
        }

        $username = \is_string($data['username'] ?? null) ? $data['username'] : '';
        $password = \is_string($data['password'] ?? null) ? $data['password'] : '';

        if ('' === $username || '' === $password) {
            throw new AuthenticationException('Missing username or password.');
        }

        return new Passport(
            new UserBadge($username, $this->credentialsVerifier->loadOrCreateUser(...)),
            new CustomCredentials($this->credentialsVerifier->verifyPassword(...), $password),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var UserInterface $user */
        $user = $token->getUser();

        return new JsonResponse(['token' => $this->jwtManager->create($user)]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
    }
}
