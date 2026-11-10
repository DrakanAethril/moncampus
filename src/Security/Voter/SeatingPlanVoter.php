<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SeatingPlan;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * « Qui fait quoi » on a seating plan (design/validated/plans-de-classe.md, §7):
 *
 * - **VIEW** - read, print, duplicate: its author and the colleagues it is shared with;
 * - **EDIT** - move students, change the rules and the room, save: its author, and those
 *   colleagues when the plan was shared « en modification »;
 * - **MANAGE** - rename, share, delete: its author alone.
 *
 * Nobody else, administrators included: a plan says where each student of a class sits, and it is
 * its author's in the way a board or a group lot is. The controllers turn a refusal into a 404.
 */
class SeatingPlanVoter extends Voter
{
    public const string VIEW = 'SEATING_PLAN_VIEW';
    public const string EDIT = 'SEATING_PLAN_EDIT';
    public const string MANAGE = 'SEATING_PLAN_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::MANAGE], true) && $subject instanceof SeatingPlan;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof SeatingPlan) {
            return false;
        }

        if ($subject->isOwnedBy($user)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $subject->isSharedWith($user),
            self::EDIT => $subject->isSharedCanEdit() && $subject->isSharedWith($user),
            default => false,
        };
    }
}
