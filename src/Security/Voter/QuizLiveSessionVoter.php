<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\QuizLiveSession;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who runs a live contest and reads its results (design/validated/quiz-live-public.md): its host;
 * and, for a contest of a class, whoever teaches that class - staff included, the rule the class's
 * screens already followed (StructureAccessChecker::isProgramTeacher()). A contest of no class is its
 * host's alone. Playing is not this voter's: a player is a participant (QuizLiveSessionService).
 *
 * The controllers turn a refusal into a 404: somebody else's contest does not exist.
 */
class QuizLiveSessionVoter extends Voter
{
    public const string MANAGE = 'QUIZ_LIVE_MANAGE';

    private const array STAFF = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof QuizLiveSession;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof QuizLiveSession) {
            return false;
        }

        if ($subject->getHost() === $user) {
            return true;
        }

        $program = $subject->getProgram();
        if (null === $program) {
            return false;
        }

        return [] !== array_intersect(self::STAFF, $user->getRoles()) || $program->getTeachers()->contains($user);
    }
}
