<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Service\ContentShareAudience;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A QuizTemplate is a teacher's personal library content - only its owning teacher, or staff, may
 * edit/duplicate/delete/launch it. Mirrors SequenceTemplateVoter exactly, VIEW included: a colleague
 * the quiz was shared with reads it and never edits it
 * (design/validated/content-sharing-between-teachers.md).
 */
class QuizTemplateVoter extends Voter
{
    public const string EDIT = 'QUIZ_TEMPLATE_EDIT';

    /** Reading the quiz in full, read-only: its owner, staff, or somebody it was shared with. */
    public const string VIEW = 'QUIZ_TEMPLATE_VIEW';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly ContentShareAudience $shareAudience,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::VIEW], true) && $subject instanceof QuizTemplate;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var QuizTemplate $template */
        $template = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $mayEdit = $this->accessChecker->isStaff() || $template->getTeacher() === $user;

        return match ($attribute) {
            self::EDIT => $mayEdit,
            self::VIEW => $mayEdit || $this->shareAudience->isSharedWith($template, $user),
            default => false,
        };
    }
}
