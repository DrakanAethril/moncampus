<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SequenceFolder;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A sequence folder is a teacher's own classement: its owner, or staff, and nobody else.
 *
 * One attribute and not a VIEW/EDIT pair, for the reason App\Security\Voter\QuizFolderVoter records:
 * a folder is never shared. Sharing hands over **a séquence**, which the recipient duplicates into
 * their own library (App\Service\SequenceDuplicator) - and lands, like every other arrival, at their
 * root.
 */
class SequenceFolderVoter extends Voter
{
    public const string EDIT = 'SEQUENCE_FOLDER_EDIT';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof SequenceFolder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var SequenceFolder $folder */
        $folder = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->accessChecker->isStaff() || $folder->getOwner() === $user;
    }
}
