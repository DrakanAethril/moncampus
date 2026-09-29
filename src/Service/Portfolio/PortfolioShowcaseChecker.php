<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\PortfolioShowcase;
use App\Entity\ReferentialBlock;
use App\Entity\ReferentialCompetency;
use App\Enum\PortfolioClaimState;

/**
 * Does the E6 dossier cover the option's bloc 2? (R12).
 *
 * The circulaire asks for two réalisations which, together, cover the three competencies of the
 * option's block. A fiche covers what its réalisation **retained** in that block; a fiche that is
 * not validated yet counts as « en attente », never as covered. Complete means both fiches
 * validated and every competency covered. A gap is shown everywhere - on the
 * student's tab, on the class view, on the deposit - and blocks nothing: the équipe decides.
 */
final class PortfolioShowcaseChecker
{
    /**
     * @param iterable<PortfolioShowcase> $showcases
     *
     * @return array{rows: list<array{competency: ReferentialCompetency, byNumber: array<int, ?string>, covered: bool}>, complete: bool, count: int}
     */
    public function check(?ReferentialBlock $block, iterable $showcases): array
    {
        $rows = [];
        $complete = null !== $block && !$block->getCompetencies()->isEmpty();
        $count = 0;

        $list = [];
        $validated = 0;
        foreach ($showcases as $showcase) {
            $list[] = $showcase;
            ++$count;
            $validated += $showcase->isValidated() ? 1 : 0;
        }

        foreach (null === $block ? [] : $block->getCompetencies() as $competency) {
            $byNumber = [1 => null, 2 => null];
            $covered = false;

            foreach ($list as $showcase) {
                $claim = $showcase->getAchievement()?->getClaimFor($competency);
                if (null === $claim || PortfolioClaimState::Retained !== $claim->getState()) {
                    continue;
                }

                if ($showcase->isValidated()) {
                    $byNumber[$showcase->getNumber()] = SynthesisTable::RETAINED;
                    $covered = true;
                } else {
                    $byNumber[$showcase->getNumber()] = SynthesisTable::PENDING;
                }
            }

            $complete = $complete && $covered;
            $rows[] = ['competency' => $competency, 'byNumber' => $byNumber, 'covered' => $covered];
        }

        return ['rows' => $rows, 'complete' => $complete && 2 === $validated, 'count' => $count];
    }
}
