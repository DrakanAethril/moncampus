<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\GameEntry;
use App\Entity\User;
use App\Service\Game\GameRuleCatalog;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may withdraw a gesture, answer a contestation, or contest one (§8, « Sécurité »).
 *
 * The asymmetry is the whole content:
 *
 * - **CANCEL and RESPOND belong to the author, plus an administrator - and there is no implicit
 *   `ROLE_STAFF` bypass.** A gesture is somebody's signed statement about a student, and a
 *   colleague withdrawing it under the author's name is not a gesture this application offers. The
 *   administrator is there because somebody has to be able to unblock a departed teacher's line.
 * - **CONTEST belongs to the student it was addressed to, and to nobody else** - not to a teacher
 *   contesting on their behalf, which would make the seven days meaningless.
 *
 * And, per the lesson this repository already paid for on Proxmox: a Voter never queries the
 * AuthorizationChecker. `ROLE_ADMIN` is read off the token's own roles.
 */
class GameGestureVoter extends Voter
{
    public const string CANCEL = 'GAME_GESTURE_CANCEL';
    public const string RESPOND = 'GAME_GESTURE_RESPOND';
    public const string CONTEST = 'GAME_GESTURE_CONTEST';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::CANCEL, self::RESPOND, self::CONTEST], true)
            && $subject instanceof GameEntry
            && \in_array($subject->getRuleCode(), [
                GameRuleCatalog::RECOGNITION_GESTURE_BONUS,
                GameRuleCatalog::RECOGNITION_GESTURE_MALUS,
            ], true);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof GameEntry) {
            return false;
        }

        return match ($attribute) {
            self::CONTEST => $subject->getStudent()->getId() === $user->getId(),
            self::CANCEL, self::RESPOND => $subject->getAuthor()?->getId() === $user->getId()
                || \in_array('ROLE_ADMIN', $user->getRoles(), true),
            default => false,
        };
    }
}
