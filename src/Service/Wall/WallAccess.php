<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallComment;
use App\Enum\WallCardStatus;
use App\Enum\WallRole;

/**
 * The single answer to « what may this person do on this collaborative wall »
 * (design/design_handoff_murs_collaboratifs, « Rôles et permissions »).
 *
 * | Somebody is…                                             | …on the wall  |
 * |----------------------------------------------------------|---------------|
 * | its owner                                                | Owner         |
 * | a named member, teaching or staff, of a teacher's wall   | Manager       |
 * | any other named member                                   | Participant   |
 * | a student of a class the wall is shared with             | Participant   |
 * | anybody else - administrators included                   | nobody        |
 *
 * So a colleague a teacher shares a wall with runs it alongside them, while everybody a *student*
 * invites - classmates and teachers alike - is a guest on the student's wall: the student keeps
 * their own settings and their own moderation. Only the owner shares and deletes.
 *
 * | Gesture                                          | Owner / Manager | Participant                                   |
 * |--------------------------------------------------|-----------------|-----------------------------------------------|
 * | settings, lists, validating, copying, duplicating | yes             | no                                            |
 * | adding a card                                    | yes             | if « Ajout de cartes par les participants »   |
 * | moving, changing, ticking, deleting a card       | yes             | their own, or all if « Modification des cartes des autres » |
 * | reading a card awaiting validation               | yes             | their own only                                |
 * | commenting                                       | if « Commentaires » is on, on any card they read                |
 *
 * There is no bypass: a wall nobody opened to you does not exist for you, whatever your role - the
 * rule of the virtual board. Nothing here reads the session; it takes the wall and the person, so
 * the whole table is testable on entities built in memory.
 */
final class WallAccess
{
    /** The roles that make somebody « personnel » rather than a student or an outside account. */
    public const array STAFF_ROLES = ['ROLE_ADMIN', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    /** Who may have walls at all: the personnel and the students. Tutors and outside accounts do not. */
    public const array ELIGIBLE_ROLES = [...self::STAFF_ROLES, 'ROLE_STUDENT'];

    /** The same list as an `#[IsGranted]` expression, for the controllers' door. */
    public const string ELIGIBLE_EXPRESSION = 'is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_STUDENT")';

    public static function isStaff(User $user): bool
    {
        return [] !== array_intersect(self::STAFF_ROLES, $user->getRoles());
    }

    public static function isEligible(User $user): bool
    {
        return [] !== array_intersect(self::ELIGIBLE_ROLES, $user->getRoles());
    }

    public function roleOf(Wall $wall, User $user): ?WallRole
    {
        if (!self::isEligible($user)) {
            return null;
        }

        if ($wall->isOwnedBy($user)) {
            return WallRole::Owner;
        }

        if ($wall->getMembers()->contains($user)) {
            return self::isStaff($user) && self::isStaff($wall->getOwner()) ? WallRole::Manager : WallRole::Participant;
        }

        foreach ($wall->getPrograms() as $program) {
            if ($program->getStudents()->contains($user)) {
                return WallRole::Participant;
            }
        }

        return null;
    }

    public function mayView(Wall $wall, User $user): bool
    {
        return null !== $this->roleOf($wall, $user);
    }

    /** The settings, the lists, the validation of cards, the copies. */
    public function mayManage(Wall $wall, User $user): bool
    {
        return true === $this->roleOf($wall, $user)?->manages();
    }

    /** Sharing and deleting stay with whoever created the wall. */
    public function mayOwn(Wall $wall, User $user): bool
    {
        return WallRole::Owner === $this->roleOf($wall, $user);
    }

    public function mayAddCard(Wall $wall, User $user): bool
    {
        $role = $this->roleOf($wall, $user);

        return null !== $role && ($role->manages() || $wall->mayParticipantsAdd());
    }

    /**
     * The state a card this person writes on this wall starts in - also asked for a card that
     * arrives from another wall, which is moderated by the wall it lands on, not the one it left.
     */
    public function statusOfNewCard(Wall $wall, User $user): WallCardStatus
    {
        return $wall->isModerated() && !$this->mayManage($wall, $user) ? WallCardStatus::Pending : WallCardStatus::Published;
    }

    public function maySeeCard(WallCard $card, User $user): bool
    {
        $role = $this->roleOf($card->getWall(), $user);
        if (null === $role) {
            return false;
        }

        return !$card->isPending() || $role->manages() || $card->isWrittenBy($user);
    }

    /** Moving, changing, ticking and deleting are one right: the handoff's table gives them one row. */
    public function mayEditCard(WallCard $card, User $user): bool
    {
        $wall = $card->getWall();
        $role = $this->roleOf($wall, $user);
        if (null === $role) {
            return false;
        }

        if ($role->manages() || $card->isWrittenBy($user)) {
            return true;
        }

        // A card still awaiting validation is nobody's to change but its author's and the
        // managers': « Modification des cartes des autres » opens what a participant can read.
        return $wall->mayParticipantsEditOthers() && !$card->isPending();
    }

    public function mayComment(WallCard $card, User $user): bool
    {
        return $card->getWall()->areCommentsEnabled() && $this->maySeeCard($card, $user);
    }

    public function mayDeleteComment(WallComment $comment, User $user): bool
    {
        $card = $comment->getCard();

        return $this->mayComment($card, $user) && ($comment->isWrittenBy($user) || $this->mayManage($card->getWall(), $user));
    }
}
