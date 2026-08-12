<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SequenceInstance;
use App\Entity\User;
use App\Security\StructureAccessChecker;
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

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof SequenceInstance;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var SequenceInstance $sequence */
        $sequence = $subject;

        if (!$token->getUser() instanceof User) {
            return false;
        }

        if ($this->accessChecker->isStaff()) {
            return true;
        }

        if (!$this->accessChecker->isProgramVisible($sequence->getProgram())) {
            return false;
        }

        // isProgramTeacher() excludes students on purpose (see StructureAccessChecker), so this is
        // the teaching side of the program and not simply "anyone who can see it".
        return $this->accessChecker->isProgramTeacher($sequence->getProgram())
            || $sequence->isVisibleToStudentsAt();
    }
}
