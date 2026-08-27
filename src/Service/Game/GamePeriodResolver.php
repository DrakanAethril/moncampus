<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;

/**
 * Which period a formation is playing, and how many its cursus has.
 *
 * The game invents no calendar: its period **is** the App\Entity\EvaluationPeriod of the formation's
 * period group (§4, decision 2). A second calendar to keep would fall out of step with the first
 * class council landing after a game closure.
 *
 * Everything here answers null rather than guessing when a formation carries no period group. A
 * program with no calendar has no period to score, no closure to run and no XP coefficient - and a
 * divisor invented here would quietly pay a whole cursus for a single period.
 */
final class GamePeriodResolver
{
    /** @return list<EvaluationPeriod> in calendar order */
    public function periodsOf(Program $program): array
    {
        $group = $program->getEvaluationPeriodGroup();

        if (null === $group) {
            return [];
        }

        $periods = array_values($group->getPeriods()->toArray());
        usort($periods, static fn (EvaluationPeriod $a, EvaluationPeriod $b): int => ($a->getStartDate() <=> $b->getStartDate()) ?: ($a->getId() <=> $b->getId()));

        return $periods;
    }

    /** The number of periods the cursus counts - the divisor of the XP formula, never a literal. */
    public function periodCount(Program $program): int
    {
        return \count($this->periodsOf($program));
    }

    public function currentPeriod(Program $program, ?\DateTimeImmutable $now = null): ?EvaluationPeriod
    {
        $now ??= new \DateTimeImmutable();

        foreach ($this->periodsOf($program) as $period) {
            if ($period->contains($now)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * The period the screens work on: the one running now, or the last one that ended.
     *
     * Between two periods - a summer, a fortnight of holidays - the screens keep showing the period
     * that just finished rather than emptying themselves, which is what a student comes looking for
     * the day after a closure.
     */
    public function activePeriod(Program $program, ?\DateTimeImmutable $now = null): ?EvaluationPeriod
    {
        $now ??= new \DateTimeImmutable();

        $current = $this->currentPeriod($program, $now);
        if (null !== $current) {
            return $current;
        }

        $last = null;
        foreach ($this->periodsOf($program) as $period) {
            $end = $period->getEndDate();
            if (null !== $end && $end < $now) {
                $last = $period;
            }
        }

        return $last;
    }

    /** The period after this one, or null on the last of the cursus. */
    public function nextPeriod(Program $program, EvaluationPeriod $period): ?EvaluationPeriod
    {
        $periods = $this->periodsOf($program);

        foreach ($periods as $offset => $candidate) {
            if ($candidate->getId() === $period->getId()) {
                return $periods[$offset + 1] ?? null;
            }
        }

        return null;
    }

    /** Which period a date falls into - what files a ledger line under the right period. */
    public function periodContaining(Program $program, \DateTimeImmutable $date): ?EvaluationPeriod
    {
        foreach ($this->periodsOf($program) as $period) {
            if ($period->contains($date)) {
                return $period;
            }
        }

        return null;
    }
}
