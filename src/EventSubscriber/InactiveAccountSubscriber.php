<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\AccountStatusChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The fourth way into the application, and the one nobody thinks of: a session that was already
 * open when the account was deactivated.
 *
 * App\Security\AccountStatusChecker, the firewalls' user_checker, closes the three others - the
 * login form, POST /api/login and its JWT, the magic link - because each of them authenticates,
 * and Symfony runs a user checker on every authentication. A session does not authenticate: the
 * token comes back out of the session and ContextListener refreshes the user straight from the
 * provider, consulting no checker at all (vendor/symfony/security-http/Firewall/ContextListener.php:
 * refreshUser() calls the provider and hasUserChanged(), nothing else). So a deactivation would
 * otherwise only take effect at the next login, which is precisely the request that will not come.
 *
 * Deactivating somebody must make them fall at their very next action. This drops the token and
 * sends them back to /login with the same message the checker raises, whose verdict it borrows
 * rather than restating - one rule, two callers.
 *
 * Stateless firewalls are skipped: /api re-authenticates on every request, so the checker has
 * already refused there, and there is no session to invalidate nor a /login to redirect a JSON
 * client to.
 */
class InactiveAccountSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AccountStatusChecker $accountStatusChecker,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $firewall = $this->security->getFirewallConfig($request);

        if (null === $firewall || $firewall->isStateless()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || !$this->accountStatusChecker->isRefused($user)) {
            return;
        }

        // Logging out is what we are doing, so let the logout route do it rather than racing it.
        if ('app_logout' === $request->attributes->get('_route')) {
            return;
        }

        $this->tokenStorage->setToken(null);

        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->invalidate();

            if ($session instanceof FlashBagAwareSessionInterface) {
                // Set after invalidate(), which empties the bag - templates/security/login.html.twig
                // renders app.flashes, the same slot TokenDeauthenticatedSubscriber writes to.
                $session->getFlashBag()->add('error', 'accountDeactivatedFlashMessage');
            }
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Same slot as App\EventSubscriber\ForcePasswordRenewalSubscriber, and for the same
            // reason: after the firewall (priority 8) has restored the token, after RouterListener
            // (32) has resolved _route.
            KernelEvents::REQUEST => [['onKernelRequest', 7]],
        ];
    }
}
