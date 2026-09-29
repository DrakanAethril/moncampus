<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioShowcase;
use App\Entity\ReferentialBlock;
use App\Entity\User;
use App\Enum\PortfolioClaimState;

/**
 * Opens, writes and submits the E6 fiches (design/validated/portfolio.md §5-6, R11-R13).
 *
 * - A fiche is opened **from a validated réalisation** that retained at least one competency of the
 *   option's bloc 2, into slot 1 or 2, and never twice on the same réalisation.
 * - Its four rubrics are the student's; a validated fiche whose text changes goes back to
 *   « À valider » (R3, same rule as the réalisation).
 * - « Modalités d'accès aux productions » is required to submit (R13) - the commission must be able
 *   to reach the productions without the platform.
 */
class PortfolioShowcaseWriter
{
    /**
     * The réalisations a fiche may be opened from.
     *
     * @return list<PortfolioAchievement>
     */
    public function candidates(Portfolio $portfolio, ?ReferentialBlock $block): array
    {
        if (null === $block) {
            return [];
        }

        $used = [];
        foreach ($portfolio->getShowcases() as $showcase) {
            $used[(int) $showcase->getAchievement()?->getId()] = true;
        }

        $candidates = [];
        foreach ($portfolio->getAchievements() as $achievement) {
            if (!$achievement->isValidated() || isset($used[(int) $achievement->getId()])) {
                continue;
            }

            foreach ($achievement->getClaims() as $claim) {
                if (PortfolioClaimState::Retained === $claim->getState() && $claim->getCompetency()?->getBlock()?->getId() === $block->getId()) {
                    $candidates[] = $achievement;
                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * @throws \DomainException when the slot is taken or the réalisation cannot carry a fiche
     */
    public function open(Portfolio $portfolio, int $number, PortfolioAchievement $achievement, ?ReferentialBlock $block): PortfolioShowcase
    {
        if (null !== $portfolio->getShowcase($number)) {
            throw new \DomainException('portfolioShowcaseSlotTakenError');
        }

        foreach ($this->candidates($portfolio, $block) as $candidate) {
            if ($candidate === $achievement) {
                return new PortfolioShowcase($portfolio, $number, $achievement);
            }
        }

        throw new \DomainException('portfolioShowcaseNotEligibleError');
    }

    /**
     * @param list<User> $reviewers
     *
     * @return bool whether the save reopened a validated fiche
     */
    public function apply(PortfolioShowcase $showcase, ?string $conditions, ?string $resources, ?string $access, ?string $description, ?int $reviewerId, array $reviewers): bool
    {
        $before = PortfolioSnapshot::ofShowcase($showcase);

        $showcase->setConditionsHtml($conditions);
        $showcase->setResourcesHtml($resources);
        $showcase->setAccessHtml($access);
        $showcase->setDescriptionHtml($description);

        $reviewer = null;
        foreach ($reviewers as $candidate) {
            if ($candidate->getId() === $reviewerId) {
                $reviewer = $candidate;
            }
        }
        $showcase->setRequestedReviewer($reviewer);

        return $this->afterChange($showcase, $before);
    }

    /**
     * @param array<string, mixed> $before
     */
    public function afterChange(PortfolioShowcase $showcase, array $before): bool
    {
        if ([] === PortfolioSnapshot::changedKeys($before, PortfolioSnapshot::ofShowcase($showcase))) {
            return false;
        }

        return $showcase->touch();
    }

    /** @return list<string> */
    public function submissionErrors(PortfolioShowcase $showcase): array
    {
        $errors = [];

        if (true !== $showcase->getAchievement()?->isValidated()) {
            $errors[] = 'portfolioShowcaseAchievementNotValidatedError';
        }

        if (null === $showcase->getAccessHtml()) {
            $errors[] = 'portfolioShowcaseAccessRequiredError';
        }

        if (null === $showcase->getDescriptionHtml()) {
            $errors[] = 'portfolioShowcaseDescriptionRequiredError';
        }

        return $errors;
    }

    /** @return list<string> */
    public function submit(PortfolioShowcase $showcase): array
    {
        $errors = $this->submissionErrors($showcase);

        if ([] === $errors) {
            $showcase->submit();
        }

        return $errors;
    }
}
