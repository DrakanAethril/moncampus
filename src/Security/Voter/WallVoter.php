<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallComment;
use App\Service\Wall\WallAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Maps the three objects of a collaborative wall onto App\Service\Wall\WallAccess, where the rule
 * lives and is tested. Each attribute belongs to one kind of subject, and an attribute asked of the
 * wrong kind is not this voter's to answer.
 *
 * The controllers turn a refusal to *read* into a 404, not a 403: a wall nobody opened to you does
 * not exist.
 */
class WallVoter extends Voter
{
    public const string VIEW = 'WALL_VIEW';
    public const string MANAGE = 'WALL_MANAGE';
    public const string OWN = 'WALL_OWN';
    public const string ADD_CARD = 'WALL_ADD_CARD';

    public const string CARD_VIEW = 'WALL_CARD_VIEW';
    public const string CARD_EDIT = 'WALL_CARD_EDIT';
    public const string CARD_COMMENT = 'WALL_CARD_COMMENT';

    public const string COMMENT_DELETE = 'WALL_COMMENT_DELETE';

    private const array WALL_ATTRIBUTES = [self::VIEW, self::MANAGE, self::OWN, self::ADD_CARD];
    private const array CARD_ATTRIBUTES = [self::CARD_VIEW, self::CARD_EDIT, self::CARD_COMMENT];

    public function __construct(private readonly WallAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match (true) {
            $subject instanceof Wall => \in_array($attribute, self::WALL_ATTRIBUTES, true),
            $subject instanceof WallCard => \in_array($attribute, self::CARD_ATTRIBUTES, true),
            $subject instanceof WallComment => self::COMMENT_DELETE === $attribute,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match (true) {
            $subject instanceof Wall => match ($attribute) {
                self::VIEW => $this->access->mayView($subject, $user),
                self::MANAGE => $this->access->mayManage($subject, $user),
                self::OWN => $this->access->mayOwn($subject, $user),
                self::ADD_CARD => $this->access->mayAddCard($subject, $user),
                default => false,
            },
            $subject instanceof WallCard => match ($attribute) {
                self::CARD_VIEW => $this->access->maySeeCard($subject, $user),
                self::CARD_EDIT => $this->access->mayEditCard($subject, $user),
                self::CARD_COMMENT => $this->access->mayComment($subject, $user),
                default => false,
            },
            $subject instanceof WallComment => $this->access->mayDeleteComment($subject, $user),
            default => false,
        };
    }
}
