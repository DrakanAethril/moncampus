<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioClaim;
use App\Entity\ReferentialCompetency;
use App\Entity\User;
use App\Repository\PortfolioShowcaseRepository;

/**
 * Writes what a student changes on a réalisation, and holds the rules of R3 and R11:
 *
 * - a validated réalisation that **changes** - its text, its claims, its evidence - goes back to
 *   « À valider » as a new revision; a save that changes nothing leaves it validated;
 * - an E6 fiche follows its réalisation: when the réalisation reopens, so does the fiche;
 * - a claim can only name a competency the student may claim - the synthesis block, or the E6
 *   block of their own option.
 *
 * Submission is checked here too: a réalisation goes to a validateur complete, or not at all.
 */
class PortfolioAchievementWriter
{
    public function __construct(
        private readonly PortfolioShowcaseRepository $showcases,
    ) {
    }

    /**
     * @param list<ReferentialCompetency> $claimable
     * @param list<User>                  $reviewers the validateurs the student may ask for
     *
     * @return bool whether the save reopened a validated réalisation
     */
    public function apply(PortfolioAchievement $achievement, AchievementInput $input, array $claimable, array $reviewers): bool
    {
        $before = PortfolioSnapshot::ofAchievement($achievement);

        $achievement->setTitle($input->title);
        $achievement->setSetting($input->setting);
        $achievement->setStartsOn($input->startsOn);
        $achievement->setEndsOn($input->endsOn);
        $achievement->setOrganisation($input->organisation);
        $achievement->setPlace($input->place);
        $achievement->setTeamwork($input->teamwork);
        $achievement->setTeamNote($input->teamwork ? $input->teamNote : null);
        $achievement->setDescriptionHtml($input->descriptionHtml);

        $reviewer = null;
        foreach ($reviewers as $candidate) {
            if ($candidate->getId() === $input->requestedReviewerId) {
                $reviewer = $candidate;
            }
        }
        $achievement->setRequestedReviewer($reviewer);

        $byId = [];
        foreach ($claimable as $competency) {
            $byId[(int) $competency->getId()] = $competency;
        }

        foreach ($achievement->getClaims()->toArray() as $claim) {
            $id = (int) $claim->getCompetency()?->getId();
            if (!\array_key_exists($id, $input->claims) || !isset($byId[$id])) {
                $achievement->removeClaim($claim);
            }
        }

        foreach ($input->claims as $competencyId => $justification) {
            if (!isset($byId[$competencyId])) {
                continue;
            }

            $claim = $achievement->getClaimFor($byId[$competencyId]);
            if (null === $claim) {
                new PortfolioClaim($achievement, $byId[$competencyId], $justification);
            } else {
                $claim->setJustification($justification);
            }
        }

        return $this->afterChange($achievement, $before);
    }

    /**
     * Called after evidence was added or removed - evidence is part of what was validated (R3).
     *
     * @param array<string, mixed> $before the snapshot taken before the change
     */
    public function afterChange(PortfolioAchievement $achievement, array $before): bool
    {
        if ([] === PortfolioSnapshot::changedKeys($before, PortfolioSnapshot::ofAchievement($achievement))) {
            return false;
        }

        $reopened = $achievement->touch();

        if ($reopened) {
            $this->followers($achievement);
        }

        return $reopened;
    }

    /**
     * What stands between this réalisation and « À valider ».
     *
     * @return list<string> translation keys; empty when it may be submitted
     */
    public function submissionErrors(PortfolioAchievement $achievement): array
    {
        $errors = [];

        if ('' === $achievement->getTitle()) {
            $errors[] = 'portfolioAchievementTitleRequiredError';
        }

        if (null === $achievement->getStartsOn() || null === $achievement->getEndsOn()) {
            $errors[] = 'portfolioAchievementDatesRequiredError';
        } elseif ($achievement->getEndsOn() < $achievement->getStartsOn()) {
            $errors[] = 'portfolioAchievementDatesOrderError';
        }

        if ($achievement->getClaims()->isEmpty()) {
            $errors[] = 'portfolioAchievementClaimRequiredError';
        }

        foreach ($achievement->getClaims() as $claim) {
            if ('' === $claim->getJustification()) {
                $errors[] = 'portfolioAchievementJustificationRequiredError';
                break;
            }
        }

        if ($achievement->isTeamwork() && null === $achievement->getTeamNote()) {
            $errors[] = 'portfolioAchievementTeamNoteRequiredError';
        }

        return $errors;
    }

    /** @return list<string> the refusals, empty when the réalisation went to « À valider » */
    public function submit(PortfolioAchievement $achievement): array
    {
        $errors = $this->submissionErrors($achievement);

        if ([] === $errors) {
            $achievement->submit();
            $this->followers($achievement);
        }

        return $errors;
    }

    private function followers(PortfolioAchievement $achievement): void
    {
        foreach ($this->showcases->findBy(['achievement' => $achievement]) as $showcase) {
            $showcase->followAchievement();
        }
    }
}
