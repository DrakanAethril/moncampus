<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\TokenDeauthenticatedEvent;

/**
 * Fires whenever App\Security\LdapAuthenticator's ContextListener discards a still-active
 * session's security token because the underlying user changed since it was issued - most often
 * a role change while already logged in (App\Entity\User::getRoles() is computed live on every
 * request from LDAP-synced roles + manual groups, so an admin editing a group, editing someone's
 * manual groups, or running an LDAP sync takes effect for that person's very next request, no
 * re-login involved), or a genuinely deleted/renamed account. Without this, the redirect to
 * /login that follows is completely silent - looks like a broken app rather than an explained
 * logout. The session is still alive at this point (this runs mid-request, before the redirect),
 * so a flash set here survives into the /login render - see App\Controller\SecurityController and
 * templates/security/login.html.twig's app.flashes loop, already built for exactly this case.
 */
class TokenDeauthenticatedSubscriber implements EventSubscriberInterface
{
    public function onTokenDeauthenticated(TokenDeauthenticatedEvent $event): void
    {
        $session = $event->getRequest()->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', 'sessionExpiredFlashMessage');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [TokenDeauthenticatedEvent::class => 'onTokenDeauthenticated'];
    }
}
