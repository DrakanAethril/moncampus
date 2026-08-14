<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\PostValue;
use App\Entity\User;
use App\Enum\PlatformActivityType;
use App\Service\PlatformActivityRecorder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Authenticates against LDAP (bind-based) and mirrors the account into a local
 * App\Entity\User row, created on first login, so the rest of the app can attach
 * relations to a stable Doctrine entity instead of a transient LDAP identity.
 *
 * The actual LDAP bind/search + JIT-provisioning logic lives in LdapCredentialsVerifier, shared
 * with ApiLdapAuthenticator (the stateless JSON login the Flutter app calls) - this class only
 * adds the web-form-specific bits (CSRF token, remember-me, session redirect).
 */
class LdapAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly LdapCredentialsVerifier $credentialsVerifier,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PlatformActivityRecorder $activityRecorder,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $username = PostValue::string($request, '_username');
        $password = PostValue::string($request, '_password');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);

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
            [
                new CsrfTokenBadge('authenticate', PostValue::string($request, '_csrf_token')),
                new RememberMeBadge(),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        // Journalisé ici et non dans un écouteur global : c'est l'authenticator qui sait par quel
        // moyen on s'est connecté, et c'est la distinction que le journal doit porter (voir
        // MagicLinkAuthenticator pour l'autre moyen).
        $this->activityRecorder->record(PlatformActivityType::LoginPassword, $user instanceof User ? $user : null, $request);

        if ($user instanceof User && $user->isMustChangePassword()) {
            return new RedirectResponse($this->urlGenerator->generate('app_password_renewal'));
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }
}
