<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SequenceInstance;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Service\AccessConditionGate;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Reading a sequence in the course space.
 *
 * Two conditions stack for a student - the Program must be visible to them *and* the sequence must
 * be published - while teaching staff only need the first. That asymmetry is deliberate: somebody
 * has to be able to proof-read a sequence before opening it, and the whole feature defaults to
 * Hidden precisely so that reading it early is a teacher's privilege.
 *
 * Publication is not an audience: a published sequence stays inside its Program, which is why
 * isProgramVisible() is checked for students too rather than replaced by the visibility flag.
 */
class SequenceInstanceVoter extends Voter
{
    public const string VIEW = 'SEQUENCE_INSTANCE_VIEW';

    /**
     * Deciding *when* the students read it - the publication selector, the per-séance visibility and
     * the « Visible » boxes of the resources.
     *
     * Narrower than VIEW on purpose: every teacher of the program may read a sequence early, but the
     * one who instantiated it is the one who says it is ready. A colleague teaching the same class
     * has their own instances (App\Repository\SequenceInstanceRepository::findForProgramCreatedBy),
     * and publishing someone else's course on their behalf is not a gesture this screen should
     * offer. Staff keep the override they have everywhere else.
     */
    public const string PUBLISH = 'SEQUENCE_INSTANCE_PUBLISH';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly AccessConditionGate $accessGate,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::PUBLISH], true) && $subject instanceof SequenceInstance;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var SequenceInstance $sequence */
        $sequence = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->accessChecker->isStaff()) {
            return true;
        }

        if (self::PUBLISH === $attribute) {
            return $sequence->getCreatedBy() === $user;
        }

        if (!$this->accessChecker->isProgramVisible($sequence->getProgram())) {
            return false;
        }

        // isProgramTeacher() excludes students on purpose (see StructureAccessChecker), so this is
        // the teaching side of the program and not simply "anyone who can see it".
        if ($this->accessChecker->isProgramTeacher($sequence->getProgram())) {
            return true;
        }

        // Publication and access condition are two different questions, and both have to hold: the
        // condition is decided here rather than in the template that greys the row, because a greyed
        // row still names its address.
        return $sequence->isVisibleToStudentsAt() && $this->accessGate->isOpen($sequence, $user);
    }
}
