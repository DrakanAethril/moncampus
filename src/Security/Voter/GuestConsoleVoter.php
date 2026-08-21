<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\GuestAccount;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may open a terminal on a machine, through the account they hold on it.
 *
 * One sentence, and both halves are required: **the account is mine, and I teach in the formation
 * of its batch.**
 *
 * A separate voter from App\Security\Voter\GuestAccountVoter rather than a second attribute on it,
 * because they answer different questions and must keep answering only their own: that one says
 * « this account is mine », which is what lets a student start their machine and set a password on
 * it. A console is not that. It opens on `moncampus`, the account the platform holds the
 * credentials for, and `moncampus` has passwordless `sudo` - so a console is root on a machine
 * where a whole practical class is working. A student already has a shell there: their own account,
 * with the password they set themselves from their card. Giving them a second one, root, on their
 * classmates' machine, is the thing this second half refuses.
 *
 * **There is no ROLE_STAFF bypass here, and there must never be one.** An administrator reaches
 * every machine through their own door, /infrastructure, which access_control guards on ROLE_ADMIN.
 * Two doors, two rules, and neither widens the other. For the same reason the teaching half is read
 * off Program::$teachers directly rather than through StructureAccessChecker::isProgramTeacher(),
 * which is staff-bypassed by design: importing that bypass here would quietly turn « I teach this
 * class » into « I outrank it ».
 *
 * A machine whose batch names no formation has no console by this door at all - there is then no
 * class to be a teacher of, and the administrator's door is the one that applies.
 *
 * @extends Voter<string, GuestAccount>
 */
class GuestConsoleVoter extends Voter
{
    public const string CONSOLE = 'GUEST_CONSOLE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CONSOLE === $attribute && $subject instanceof GuestAccount;
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
        if (null === $ownerId || $ownerId !== $user->getId()) {
            return false;
        }

        $program = $subject->getBatch()?->getProgram();

        return null !== $program && $program->getTeachers()->contains($user);
    }
}
