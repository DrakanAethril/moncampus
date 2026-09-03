<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Enforces the "restricted session" imposed by App\Entity\User::$mustChangePassword
 * (design/design_handoff_pages_arrivee): while it's true, every route other than the renewal
 * screen itself and logout redirects back to /password/renewal, no matter what access_control
 * would otherwise allow - a plain access_control entry can't express this (it's prefix-based and
 * evaluated independently of any per-user state), so this mirrors LocaleSubscriber's shape
 * instead: a KernelEvents::REQUEST subscriber reading Security::getUser() directly.
 *
 * LdapAuthenticator/MagicLinkAuthenticator already redirect straight to app_password_renewal on
 * login when this flag is set, so in practice this subscriber mostly guards against a restricted
 * user navigating away (typing another URL, following a stale link) rather than the initial entry.
 */
class ForcePasswordRenewalSubscriber implements EventSubscriberInterface
{
    private const array ALLOWED_ROUTES = ['app_logout', 'app_password_renewal', 'app_password_renewal_confirmation'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isMustChangePassword()) {
            return;
        }

        // Not while an administrator is impersonating this account (« Se connecter en tant que »):
        // the restricted session is a rule about the *person* whose password it is, and the
        // administrator standing in their place has neither their password nor any business
        // renewing it. Without this, impersonating somebody who has never signed in traps the
        // administrator on a screen that would file an LDAP password change under a third name.
        if ($this->security->isGranted('IS_IMPERSONATOR')) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_password_renewal')));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Must run after the security firewall (priority 8) has authenticated the token, and
            // after Symfony's RouterListener (priority 32) has resolved _route.
            KernelEvents::REQUEST => [['onKernelRequest', 7]],
        ];
    }
}
