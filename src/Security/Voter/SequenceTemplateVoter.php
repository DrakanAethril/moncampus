<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Service\ContentShareAudience;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A SequenceTemplate is a teacher's personal library content - only its owning teacher, or staff,
 * may edit/delete/instantiate it.
 *
 * VIEW is the wider door a colleague comes through: EDIT, or a share of this séquence that reaches
 * them (design/validated/content-sharing-between-teachers.md). **EDIT is untouched by sharing, here
 * and in every other voter** - a reader never becomes a writer, and « un partage donne à lire »
 * would not survive a single exception.
 */
class SequenceTemplateVoter extends Voter
{
    public const string EDIT = 'SEQUENCE_TEMPLATE_EDIT';

    /** Reading the séquence in full, read-only: its owner, staff, or somebody it was shared with. */
    public const string VIEW = 'SEQUENCE_TEMPLATE_VIEW';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly ContentShareAudience $shareAudience,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::VIEW], true) && $subject instanceof SequenceTemplate;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var SequenceTemplate $template */
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
