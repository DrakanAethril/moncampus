<?php

namespace App\Security\Voter;

use App\Entity\LessonSession;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to a LessonSession's cahier de texte (LessonLog): anyone with visibility on the
 * session's Program (student, teacher, staff - see StructureAccessChecker::isProgramVisible())
 * can view it, but only staff or the session's own teacher can edit it. The subject is always the
 * LessonSession, not the LessonLog itself, since a session may not have a log row yet.
 */
class LessonLogVoter extends Voter
{
    public const string VIEW = 'LESSON_LOG_VIEW';
    public const string EDIT = 'LESSON_LOG_EDIT';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof LessonSession;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var LessonSession $session */
        $session = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->accessChecker->isStaff()) {
            return true;
        }

        if (self::EDIT === $attribute) {
            return $session->getTeacher() === $user;
        }

        return $this->accessChecker->isProgramVisible($session->getProgram());
    }
}
