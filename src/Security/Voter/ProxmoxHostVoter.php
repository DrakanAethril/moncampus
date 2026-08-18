<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ProxmoxHost;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what on a declared hypervisor. Three attributes, and ROLE_ADMIN on all three - the
 * whole /infrastructure area is admin-only and has no student, teacher or staff face at all.
 *
 * The role is the floor, not the answer: what actually separates the attributes is the host's own
 * declaration, so that the screens and the actions agree without each re-reading the same flags.
 *
 *   - VIEW      - list its machines and images. Granted on any host, even one that is deactivated
 *                 or unreachable: reading is how an administrator finds out it is deactivated.
 *   - OPERATE   - start, shut down, force off, reboot. Refused on a deactivated host, and refused
 *                 when the host allows neither starting nor stopping.
 *   - PROVISION - create. Refused unless the host both allows creation *and* carries the second
 *                 credential set; without the provisioning account there is nothing holding
 *                 VM.Allocate, so the wizard would fail at the first call anyway.
 *
 * There is deliberately no DESTROY attribute. The application does not delete machines, and the
 * absence of an attribute is part of how that is enforced rather than merely promised.
 */
class ProxmoxHostVoter extends Voter
{
    public const string VIEW = 'PROXMOX_HOST_VIEW';
    public const string OPERATE = 'PROXMOX_HOST_OPERATE';
    public const string PROVISION = 'PROXMOX_HOST_PROVISION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::OPERATE, self::PROVISION], true) && $subject instanceof ProxmoxHost;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var ProxmoxHost $host */
        $host = $subject;
        $user = $token->getUser();

        // The role is read off the user, the way every other Voter in this repository reads one -
        // never through AuthorizationCheckerInterface. Asking the authorization checker from inside
        // a Voter re-enters the access-decision manager while it is already deciding, and the inner
        // question answers "no" whatever the roles are: the buttons of the machines list all
        // vanished, silently, on an account holding ROLE_ADMIN.
        if (!$user instanceof User || !\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::OPERATE => $host->isActive() && ($host->isAllowStart() || $host->isAllowStop()),
            self::PROVISION => $host->isActive() && $host->canCreateGuests(),
            // supports() already filters the attribute; deny rather than throw if it ever drifts.
            default => false,
        };
    }
}
