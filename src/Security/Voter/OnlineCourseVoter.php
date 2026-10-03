<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\OnlineCourse;
use App\Entity\User;
use App\Enum\OnlineCourseStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The single answer to « qui voit quoi » on an online course
 * (design/validated/cours-en-ligne.md, §3).
 *
 * A course is its author's alone to write, administrators included - the same rule as « un travail
 * n'est qu'à son auteur ». What an administrator does hold is UNPUBLISH: the page is public, in the
 * establishment's name, so somebody other than the author has to be able to take a course offline.
 * Taking it offline is not editing it.
 *
 * VIEW is the one attribute a visitor with no account is asked about: a public course is read by
 * anybody, a draft by its author only. A course reserved for learning paths is not opened by this
 * attribute to its readers at all - they reach it through an opened step of a path, which is the
 * path's own decision (App\Service\LearningPath\LearningPathBoard).
 *
 * Callers turn a refusal into a 404, not a 403: somebody else's draft does not exist.
 */
class OnlineCourseVoter extends Voter
{
    public const string VIEW = 'ONLINE_COURSE_VIEW';
    public const string EDIT = 'ONLINE_COURSE_EDIT';
    public const string PUBLISH = 'ONLINE_COURSE_PUBLISH';
    public const string UNPUBLISH = 'ONLINE_COURSE_UNPUBLISH';
    public const string DELETE = 'ONLINE_COURSE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::PUBLISH, self::UNPUBLISH, self::DELETE], true)
            && $subject instanceof OnlineCourse;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$subject instanceof OnlineCourse) {
            return false;
        }

        $user = $token->getUser();
        $isOwner = $user instanceof User && $subject->isOwnedBy($user);
        $isAdmin = $user instanceof User && \in_array('ROLE_ADMIN', $user->getRoles(), true);

        return match ($attribute) {
            self::VIEW => $isOwner
                || OnlineCourseStatus::PublicCourse === $subject->getStatus()
                || (OnlineCourseStatus::PathOnly === $subject->getStatus() && $isAdmin),
            self::EDIT, self::PUBLISH => $isOwner,
            self::UNPUBLISH => $subject->isPublished() && ($isOwner || $isAdmin),
            // A published course is taken offline first: deleting is the one gesture that cannot be
            // undone, and it must not be how a page loses a course by accident.
            self::DELETE => $isOwner && !$subject->isPublished(),
            default => false,
        };
    }
}
