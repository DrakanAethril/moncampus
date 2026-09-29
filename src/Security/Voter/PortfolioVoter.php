<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioShowcase;
use App\Entity\User;
use App\Service\Portfolio\PortfolioValidators;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what with a portfolio (design/validated/portfolio.md, R2).
 *
 * | Attribute | Who |
 * |---|---|
 * | EDIT | the student who owns it, and nobody else - not even to correct a typo |
 * | REVIEW, ENDORSE | a designated validateur of the student's current formation and option who still teaches there (App\Service\Portfolio\PortfolioValidators) |
 * | VIEW | the owner, those validateurs, and the administration in reading |
 *
 * **No bypass for ROLE_ADMIN on REVIEW or ENDORSE**, deliberately: deciding is a designation, not
 * a rank (§1). An administrator reads every portfolio and decides about none unless they are
 * designated and teach in the class, like anybody else.
 *
 * The subject may be the portfolio or one of its pieces; a piece answers for its portfolio.
 */
class PortfolioVoter extends Voter
{
    public const string VIEW = 'PORTFOLIO_VIEW';
    public const string EDIT = 'PORTFOLIO_EDIT';
    public const string REVIEW = 'PORTFOLIO_REVIEW';
    public const string ENDORSE = 'PORTFOLIO_ENDORSE';

    /** Who reads every portfolio without deciding anything - the class view's « personnel en lecture ». */
    private const array READING_ROLES = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    public function __construct(
        private readonly PortfolioValidators $validators,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::REVIEW, self::ENDORSE], true)
            && ($subject instanceof Portfolio || $subject instanceof PortfolioAchievement || $subject instanceof PortfolioShowcase);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $portfolio = match (true) {
            $subject instanceof Portfolio => $subject,
            $subject instanceof PortfolioAchievement, $subject instanceof PortfolioShowcase => $subject->getPortfolio(),
            default => null,
        };

        if (!$user instanceof User || null === $portfolio) {
            return false;
        }

        $isOwner = $portfolio->getStudent()?->getId() === $user->getId();

        return match ($attribute) {
            self::EDIT => $isOwner,
            self::REVIEW, self::ENDORSE => !$isOwner && $this->validators->canReview($user, $portfolio),
            self::VIEW => $isOwner
                || [] !== array_intersect(self::READING_ROLES, $user->getRoles())
                || $this->validators->canReview($user, $portfolio),
            default => false,
        };
    }
}
