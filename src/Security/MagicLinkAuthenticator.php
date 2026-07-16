<?php

namespace App\Security;

use App\Service\MagicLoginService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Consumes a magic-link token mailed by App\Service\MagicLoginService::requestLink() and
 * authenticates the corresponding User - the POST counterpart to
 * App\Controller\PublicMagicLoginController::confirm()'s GET-only confirmation page (see that
 * class's docblock for why the two are split). Unlike LdapAuthenticator/ApiLdapAuthenticator,
 * there's no username to build a lazy UserBadge loader around until the token has already been
 * resolved, so consume() (which also marks it single-use) runs eagerly in authenticate() itself.
 */
class MagicLinkAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly MagicLoginService $magicLoginService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return 'app_login_magic_confirm' === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $token = (string) $request->attributes->get('token');
        $user = $this->magicLoginService->consume($token, $request->getClientIp());

        if (null === $user) {
            // Message key, not a message - resolved against the "security" translation domain
            // by login.html.twig's error.messageKey|trans(error.messageData, 'security') (see
            // translations/security.*.yaml), the same rendering LdapAuthenticator's own failures
            // already go through.
            throw new CustomUserMessageAuthenticationException('magicLoginConsumeInvalidMessage');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user),
            [new CsrfTokenBadge('magic_login_consume', $request->request->getString('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Same session slot AbstractLoginFormAuthenticator uses for LdapAuthenticator's own
        // failures - login.html.twig already renders it via AuthenticationUtils, so a failed
        // magic link surfaces through the exact same error banner as a bad LDAP password.
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
