<?php

namespace App\Security\Voter;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

// A QuizTemplate is a teacher's personal library content - only its owning teacher, or staff, may
// edit/duplicate/delete/launch it. Mirrors SequenceTemplateVoter exactly.
class QuizTemplateVoter extends Voter
{
    public const string EDIT = 'QUIZ_TEMPLATE_EDIT';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof QuizTemplate;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var QuizTemplate $template */
        $template = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->accessChecker->isStaff() || $template->getTeacher() === $user;
    }
}
