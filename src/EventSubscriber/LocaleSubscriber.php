<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the request locale from (in order) the session, then the logged-in
 * user's saved preference, falling back to the framework default (fr).
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->hasPreviousSession() && $request->getSession()->has('_locale')) {
            $request->setLocale($request->getSession()->get('_locale'));

            return;
        }

        $this->setLocaleFromUser($request);
    }

    private function setLocaleFromUser(Request $request): void
    {
        $user = $this->security->getUser();
        if ($user instanceof User && null !== $user->getLocale()) {
            $request->setLocale($user->getLocale());
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Must run after Symfony's own LocaleListener (priority 16), which reads
            // the _locale route attribute, so it doesn't get overridden by that.
            KernelEvents::REQUEST => [['onKernelRequest', 15]],
        ];
    }
}
