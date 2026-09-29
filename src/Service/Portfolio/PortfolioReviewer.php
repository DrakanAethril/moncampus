<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioReview;
use App\Entity\PortfolioShowcase;
use App\Entity\User;
use App\Enum\PortfolioReviewDecision;
use App\Enum\PortfolioState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a validateur's decision (design/validated/portfolio.md §5, R2-R3, R6, R11, R13).
 *
 * On a réalisation, each claimed competency is **retained or not**, one by one; validating needs at
 * least one retained - otherwise the réalisation is sent back. On an E6 fiche there is no decision
 * per competency (it was taken on the réalisation), but validating needs the réalisation itself
 * validated and « environnement technologique conforme à l'annexe II.E » ticked.
 *
 * Sending back must say why. Every decision is appended to the journal with the piece as it was
 * read, and only a piece « À valider » can be decided - a double click does not decide twice.
 */
class PortfolioReviewer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<int, bool>        $retained competency id => retained, for every claim
     * @param array<int, string|null> $comments competency id => a word on that one competency
     *
     * @return list<string> the refusals (translation keys); empty when the decision was written
     */
    public function decideAchievement(PortfolioAchievement $achievement, User $reviewer, PortfolioReviewDecision $decision, ?string $comment, array $retained, array $comments = []): array
    {
        if (PortfolioState::Submitted !== $achievement->getState()) {
            return ['portfolioReviewNotAwaitingError'];
        }

        $comment = null === $comment ? null : trim($comment);
        if ($decision->requiresComment() && (null === $comment || '' === $comment)) {
            return ['portfolioReviewCommentRequiredError'];
        }

        $claims = [];
        $anyRetained = false;
        foreach ($achievement->getClaims() as $claim) {
            $id = (int) $claim->getCompetency()?->getId();

            if (!\array_key_exists($id, $retained)) {
                // Validating needs an answer on every competency; sending back does not - what was
                // not answered is recorded as not retained, and asked again at the next revision.
                if (PortfolioReviewDecision::Validated === $decision) {
                    return ['portfolioReviewEveryClaimError'];
                }
                $retained[$id] = false;
            }

            $anyRetained = $anyRetained || $retained[$id];
            $claims[(string) $id] = ['retained' => $retained[$id], 'comment' => $comments[$id] ?? null];
        }

        if (PortfolioReviewDecision::Validated === $decision && !$anyRetained) {
            return ['portfolioReviewNoRetainedError'];
        }

        foreach ($achievement->getClaims() as $claim) {
            $id = (int) $claim->getCompetency()?->getId();
            $claim->decide($retained[$id], $comments[$id] ?? null);
        }

        $this->entityManager->persist(new PortfolioReview($achievement, $reviewer, $decision, $comment, $claims, PortfolioSnapshot::ofAchievement($achievement)));

        if (PortfolioReviewDecision::Validated === $decision) {
            $achievement->markValidated();
        } else {
            $achievement->markToRework();
        }

        $this->entityManager->flush();

        return [];
    }

    /**
     * @return list<string>
     */
    public function decideShowcase(PortfolioShowcase $showcase, User $reviewer, PortfolioReviewDecision $decision, ?string $comment, bool $environmentCompliant): array
    {
        if (PortfolioState::Submitted !== $showcase->getState()) {
            return ['portfolioReviewNotAwaitingError'];
        }

        $comment = null === $comment ? null : trim($comment);
        if ($decision->requiresComment() && (null === $comment || '' === $comment)) {
            return ['portfolioReviewCommentRequiredError'];
        }

        if (PortfolioReviewDecision::Validated === $decision) {
            if (true !== $showcase->getAchievement()?->isValidated()) {
                return ['portfolioReviewShowcaseAchievementNotValidatedError'];
            }

            if (!$environmentCompliant) {
                return ['portfolioReviewEnvironmentRequiredError'];
            }
        }

        $this->entityManager->persist(new PortfolioReview($showcase, $reviewer, $decision, $comment, [], PortfolioSnapshot::ofShowcase($showcase), $environmentCompliant));

        if (PortfolioReviewDecision::Validated === $decision) {
            $showcase->markValidated();
        } else {
            $showcase->markToRework();
        }

        $this->entityManager->flush();

        return [];
    }
}
