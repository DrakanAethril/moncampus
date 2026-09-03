<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * The half of the « Se connecter en tant que » rule that config/packages/security.yaml cannot
 * express: **an administrator never becomes another administrator**.
 *
 * `switch_user: { role: ROLE_ADMIN }` decides on the person *asking*, and access_control has no
 * notion of the account being asked for. So the target is checked here, on the event Symfony's
 * SwitchUserListener fires after building the impersonating token and before handing it back to be
 * stored - throwing at this point aborts the switch itself, not merely the screen behind it.
 *
 * This is the door, not the picker: App\Controller\ImpersonationController never offers an
 * administrator in its list, but the switch is a query parameter on any URL of the application, so
 * a hand-typed `?_switch_user=` reaches the firewall without ever passing through that controller.
 *
 * Why the rule at all: an impersonating token carries the *target's* roles, so the escape hatch
 * back is ROLE_PREVIOUS_ADMIN alone. Chaining one administrator into another would make « revenir à
 * mon compte » ambiguous, and would let an account that has since been demoted keep opening the
 * platform through somebody else's. One hop, downwards only.
 */
class ImpersonationSubscriber implements EventSubscriberInterface
{
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        // SwitchUserListener fires this same event on the way **out** as well, with the original -
        // necessarily administrator - account as the target: refusing there would shut the door
        // behind the administrator and leave « revenir à mon compte » answering 403 for good. The
        // two are told apart by the token being built, not by the query parameter's value: entering
        // produces a SwitchUserToken, leaving hands back the plain original one.
        if (!$event->getToken() instanceof SwitchUserToken) {
            return;
        }

        $target = $event->getTargetUser();

        if ($target instanceof User && \in_array('ROLE_ADMIN', $target->getRoles(), true)) {
            throw new AccessDeniedException('An administrator account cannot be impersonated.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [SecurityEvents::SWITCH_USER => 'onSwitchUser'];
    }
}
