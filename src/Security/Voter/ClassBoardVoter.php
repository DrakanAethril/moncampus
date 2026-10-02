<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ClassBoard;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A virtual board is its owner's, and nobody else's - administrators included, the same rule as
 * « un travail n'est qu'à son auteur ». The three attributes answer the same today; they are kept
 * apart because sharing with a colleague is decided (design/validated/tableau-virtuel.md, §11) and
 * will not open them alike.
 *
 * The controllers turn a refusal into a 404, not a 403: somebody else's board does not exist.
 */
class ClassBoardVoter extends Voter
{
    public const string VIEW = 'CLASS_BOARD_VIEW';
    public const string EDIT = 'CLASS_BOARD_EDIT';
    public const string DELETE = 'CLASS_BOARD_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true) && $subject instanceof ClassBoard;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject instanceof ClassBoard && $subject->isOwnedBy($user);
    }
}
