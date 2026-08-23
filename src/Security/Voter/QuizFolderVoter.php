<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\QuizFolder;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A quiz folder is a teacher's own classement: its owner, or staff, and nobody else.
 *
 * One attribute and not the VIEW/EDIT pair App\Security\Voter\QuizTemplateVoter carries, because a
 * folder is never shared. Sharing hands over **a quiz**, which the recipient duplicates into their
 * own library - and lands, like every other arrival, at their root.
 */
class QuizFolderVoter extends Voter
{
    public const string EDIT = 'QUIZ_FOLDER_EDIT';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof QuizFolder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var QuizFolder $folder */
        $folder = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->accessChecker->isStaff() || $folder->getOwner() === $user;
    }
}
