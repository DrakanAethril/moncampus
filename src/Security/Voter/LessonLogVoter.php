<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\LessonSession;
use App\Entity\User;
use App\Security\LessonLogEditors;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to a LessonSession's cahier de texte (LessonLog): anyone with visibility on the
 * session's Program (student, teacher, staff - see StructureAccessChecker::isProgramVisible())
 * can view it, but only whoever actually delivers the séance can write in it. The subject is always
 * the LessonSession, not the LessonLog itself, since a session may not have a log row yet.
 *
 * **Staff have no write bypass here**, and that is the one place in the application where their
 * role stops at reading. A cahier de texte is an administrative record of what one teacher did with
 * one group: a head of studies writing in it would be signing somebody else's register, and the
 * screens they actually need - the class's course view, the exports, the tracking - are all
 * reading screens. Staff who teach the créneau are let in as its teacher, like anyone else.
 *
 * Who else counts as delivering it - the co-animator - is App\Security\LessonLogEditors' question,
 * and it is a lookup rather than a property of the session, which is why it does not sit here.
 */
class LessonLogVoter extends Voter
{
    public const string VIEW = 'LESSON_LOG_VIEW';
    public const string EDIT = 'LESSON_LOG_EDIT';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly LessonLogEditors $editors,
    ) {
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

        if (self::EDIT === $attribute) {
            return $this->editors->mayEdit($session, $user);
        }

        return $this->accessChecker->isStaff() || $this->accessChecker->isProgramVisible($session->getProgram());
    }
}
