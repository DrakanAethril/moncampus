<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Progression;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

// A progression is one teacher's own planning of their own matière - only its owning teacher, a
// co-animator named on it, or staff, may open or edit it. Same shape as SequenceTemplateVoter, and
// for the same reason: the Program-visibility check the rest of the app uses would let every
// colleague of the class in, which is not what "mes progressions" means.
//
// The co-animation door stays as narrow as the other two on purpose (see
// design/validated/co-animation.md): it is a link named on THIS progression, not a property of the
// class. A colleague who teaches the same class and was never named on the plan is still refused,
// which is the difference between naming a second formateur and opening the matière.
class ProgressionVoter extends Voter
{
    public const string EDIT = 'PROGRESSION_EDIT';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Progression;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Progression $progression */
        $progression = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->accessChecker->isStaff()
            || $progression->getTeacher() === $user
            || $progression->isCoTeacher($user);
    }
}
