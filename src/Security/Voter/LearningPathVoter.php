<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\LearningPath;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * « Qui voit quoi » on a learning path (design/validated/cours-en-ligne.md, §3 and §10).
 *
 * - FOLLOW: anybody **holding an account** follows a published path; its author walks through a
 *   draft the same way, to try it. A visitor with no account is never granted - and never gets
 *   this far, the routes sitting behind the sign-in.
 * - EDIT, DELETE: its author alone, administrators included.
 * - TRACK: its author alone, **administrators not included**. The follow-up names people and their
 *   scores; it was decided to be read by the person who wrote the path and by nobody else.
 *
 * Callers turn a refusal into a 404, not a 403.
 */
class LearningPathVoter extends Voter
{
    public const string FOLLOW = 'LEARNING_PATH_FOLLOW';
    public const string EDIT = 'LEARNING_PATH_EDIT';
    public const string TRACK = 'LEARNING_PATH_TRACK';
    public const string DELETE = 'LEARNING_PATH_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::FOLLOW, self::EDIT, self::TRACK, self::DELETE], true) && $subject instanceof LearningPath;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof LearningPath) {
            return false;
        }

        $isOwner = $subject->isOwnedBy($user);

        return match ($attribute) {
            self::FOLLOW => $isOwner || $subject->isPublished(),
            self::EDIT, self::TRACK => $isOwner,
            // A path people are following is taken offline first: deleting it deletes their
            // follow-up with it, and that must be a decision, not a slip.
            self::DELETE => $isOwner && !$subject->isPublished(),
            default => false,
        };
    }
}
