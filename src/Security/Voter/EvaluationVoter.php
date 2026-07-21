<?php

namespace App\Security\Voter;

use App\Entity\Evaluation;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Carnet de notes access. Unlike StructureAccessChecker::isProgramTeacher() (any teacher of the
 * Program), MANAGE is scoped to the evaluation's own Topic::$teacher - the carnet de notes is that
 * one teacher's gradebook for that one matière, not shared across every teacher of the Program
 * (see Evaluation's docblock). VIEW additionally lets an enrolled student through, but only once
 * the evaluation is actually visible to them (Evaluation::isVisibleAt()) - callers still need to
 * scope which Grade rows a student sees to their own (never another student's, never a ranking),
 * this voter only gates the evaluation itself.
 */
class EvaluationVoter extends Voter
{
    public const string VIEW = 'EVALUATION_VIEW';
    public const string MANAGE = 'EVALUATION_MANAGE';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof Evaluation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Evaluation $evaluation */
        $evaluation = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->accessChecker->isStaff()) {
            return true;
        }

        $topic = $evaluation->getTopic();
        if (null !== $topic && $topic->getTeacher() === $user) {
            return true;
        }

        if (self::MANAGE === $attribute) {
            return false;
        }

        return null !== $topic
            && $topic->getProgram()->getStudents()->contains($user)
            && $evaluation->isVisibleAt(new \DateTimeImmutable());
    }
}
