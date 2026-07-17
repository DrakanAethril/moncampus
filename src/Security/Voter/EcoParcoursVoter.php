<?php

namespace App\Security\Voter;

use App\Entity\EcoParcours;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

// An EcoParcours is a teacher's personal e-CO content - only its owning teacher, or staff, may
// configure/locate/delete it or manage its courses. Mirrors QuizTemplateVoter exactly.
class EcoParcoursVoter extends Voter
{
    public const string EDIT = 'ECO_PARCOURS_EDIT';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof EcoParcours;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var EcoParcours $parcours */
        $parcours = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->accessChecker->isStaff() || $parcours->getTeacher() === $user;
    }
}
