<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\JsonRequestPayload;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
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

        $payload = JsonRequestPayload::fromArray($data);
        $username = $payload->string('username');
        $password = $payload->string('password');

        if ('' === $username || '' === $password) {
            throw new AuthenticationException('Missing username or password.');
        }

        return new Passport(
            new UserBadge($username, $this->credentialsVerifier->loadOrCreateUser(...)),
            // CustomCredentials only promises a UserInterface, so the verifier's own
            // (string, User) signature cannot be handed over as-is. Both firewalls resolve
            // users through LdapCredentialsVerifier::loadOrCreateUser(), which returns our own
            // User - anything else here is a misconfigured provider, not a wrong password.
            new CustomCredentials(
                fn (mixed $credentials, UserInterface $user): bool => $user instanceof User
                    && \is_string($credentials)
                    && $this->credentialsVerifier->verifyPassword($credentials, $user),
                $password,
            ),
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
        // A deactivated account is told so, where a wrong password is not: the refusal is the same
        // one the web form shows (App\Security\AccountStatusChecker), and hiding it here would only
        // send somebody who cannot log in any more into trying their password again. It reveals
        // nothing a wrong password would not - the check runs before any credential is looked at,
        // so the answer is identical whatever was typed in the password field.
        if ($exception instanceof AccountStatusException) {
            return new JsonResponse([
                'error' => 'account_disabled',
                'message' => $this->translator->trans($exception->getMessageKey(), $exception->getMessageData(), 'security'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['error' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
    }
}
