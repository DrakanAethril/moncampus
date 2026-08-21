<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\GuestAccount;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may act on a machine through the account they hold on it.
 *
 * The rule is one line and deliberately admits nothing else: **the account is yours**. Not "you are
 * a teacher", not "you are staff" - a machine of a practical class is not a thing to be started,
 * stopped or have its passwords changed by whoever happens to hold a role, and the administration
 * screens under /infrastructure exist for the people who legitimately do act on any machine.
 *
 * There is no staff bypass here for the same reason ProxmoxHostVoter has its own separate rules:
 * this voter answers a question about *ownership of an account*, and a bypass would quietly turn it
 * into a question about rank.
 *
 * @extends Voter<string, GuestAccount>
 */
class GuestAccountVoter extends Voter
{
    /** Seeing the machine, and acting on it: the same fact answers both. */
    public const string OWN = 'GUEST_ACCOUNT_OWN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::OWN === $attribute && $subject instanceof GuestAccount;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $ownerId = $subject->getUser()?->getId();

        // `null !== $ownerId` first, and it is not defensive noise: two unsaved users both answer
        // null, and `null === null` would make every account everybody's.
        return null !== $ownerId && $ownerId === $user->getId();
    }
}
