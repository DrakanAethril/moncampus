<?php

namespace App\Service;

use App\Entity\EvaluationPeriod;
use App\Entity\Progression;
use App\Enum\EvaluationNature;
use App\Repository\LessonSessionRepository;

/**
 * The completeness bar of screens 3a and 5a.
 *
 * The design defines "complète" as two conditions at once - "toutes les heures planifiées + une
 * sommative par trimestre" - but shows a single percentage, without saying how they combine. This
 * implementation scores each condition on its own 0-1 scale and averages them, so a progression
 * that has laid out every hour but posed no summative reads 50 %, not 100 %. When the Program has
 * no EvaluationPeriodGroup configured there are no trimesters to check, and the score falls back
 * to the hours ratio alone.
 *
 * Flagged in the module's recap as a point to confirm with the product owner - the rule is
 * unambiguous, its arithmetic is not.
 */
class ProgressionCompletenessCalculator
{
    public function __construct(private readonly LessonSessionRepository $lessonSessionRepository)
    {
    }

    public function percentage(Progression $progression): int
    {
        $hoursRatio = $this->hoursRatio($progression);
        $summativeRatio = $this->summativeRatio($progression);

        $score = null === $summativeRatio ? $hoursRatio : ($hoursRatio + $summativeRatio) / 2;

        return (int) round(min(1.0, max(0.0, $score)) * 100);
    }

    /** The "· 48 h" figure next to a matière on 3a: what the timetable actually allocates to it. */
    public function timetableHours(Progression $progression): float
    {
        $topic = $progression->getTopic();
        $program = $progression->getProgram();
        if (null === $topic || null === $program) {
            return 0.0;
        }

        return $this->lessonSessionRepository->findHoursByTopicForProgram($program)[(int) $topic->getId()] ?? 0.0;
    }

    private function hoursRatio(Progression $progression): float
    {
        $total = $this->timetableHours($progression);
        if ($total <= 0) {
            return 0.0;
        }

        return min(1.0, $progression->getPlacedHours() / $total);
    }

    /** @return float|null null when the Program has no grading periods to spread summatives over */
    private function summativeRatio(Progression $progression): ?float
    {
        $periods = $progression->getProgram()?->getEvaluationPeriodGroup()?->getPeriods()->toArray() ?? [];
        if ([] === $periods) {
            return null;
        }

        $covered = 0;
        foreach ($periods as $period) {
            if ($this->hasSummativeIn($progression, $period)) {
                ++$covered;
            }
        }

        return $covered / \count($periods);
    }

    private function hasSummativeIn(Progression $progression, EvaluationPeriod $period): bool
    {
        foreach ($progression->getTopic()?->getEvaluations() ?? [] as $evaluation) {
            $date = $evaluation->getDate();

            if (EvaluationNature::Summative === $evaluation->getNature()
                && null === $evaluation->getInactiveDate()
                && null !== $date
                && $period->contains($date)
            ) {
                return true;
            }
        }

        return false;
    }
}
