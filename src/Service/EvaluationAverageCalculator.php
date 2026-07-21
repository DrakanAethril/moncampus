<?php

namespace App\Service;

use App\Entity\Grade;

/**
 * The averaging/normalization math from design/design_handoff_projet/designs/
 * Carnet de notes.dc.html's norm()/studentAvg()/evalAvg()/gradeColor()/qSet(), ported 1:1 so the
 * server-computed averages (student view, PDF exports, future reporting) and the teacher grid's
 * live client-side recompute (assets/controllers/evaluation_grid_controller.js) always agree.
 *
 * A pure calculator - callers fetch the Grade rows themselves (e.g. GradeRepository) and pass them
 * in, so this stays trivially unit-testable and free of any N+1 query concerns of its own.
 */
class EvaluationAverageCalculator
{
    // Only GradeStatus::Normal counts - Excluded ("(12)") still has a value but is deliberately
    // left out of every average, same as Absent/NotEvaluated/NotTested (which have none).
    public function normalize(Grade $grade): ?float
    {
        if (!$grade->getStatus()->countsTowardAverage() || null === $grade->getValue()) {
            return null;
        }

        $evaluation = $grade->getEvaluation();
        if (null === $evaluation) {
            return null;
        }

        if (!$evaluation->countsOutOf20()) {
            return $grade->getValue();
        }

        return $evaluation->getScale() > 0 ? ($grade->getValue() / $evaluation->getScale()) * 20 : null;
    }

    /**
     * Weighted average (by each Grade's own Evaluation::$coefficient) over every countable Grade
     * given - one student's "Moy. /20" column, or their overall carnet average once a period's
     * Grades have already been filtered by the caller.
     *
     * @param list<Grade> $grades
     */
    public function studentAverage(array $grades): ?float
    {
        $sum = 0.0;
        $weightSum = 0.0;

        foreach ($grades as $grade) {
            $normalized = $this->normalize($grade);
            if (null === $normalized) {
                continue;
            }

            $coefficient = $grade->getEvaluation()?->getCoefficient() ?? 0.0;
            $sum += $normalized * $coefficient;
            $weightSum += $coefficient;
        }

        return $weightSum > 0 ? $sum / $weightSum : null;
    }

    /**
     * Unweighted mean across every student's Grade for a single Evaluation - the class average
     * shown against that evaluation's own column.
     *
     * @param list<Grade> $grades
     */
    public function evaluationAverage(array $grades): ?float
    {
        $values = [];
        foreach ($grades as $grade) {
            $normalized = $this->normalize($grade);
            if (null !== $normalized) {
                $values[] = $normalized;
            }
        }

        return [] === $values ? null : array_sum($values) / \count($values);
    }

    // Sums a Grade's rubric answers, each capped at its question's max points (design's qSet()) -
    // null (not 0) if nothing was answered yet, so a barème-graded cell can still show "empty"
    // rather than a misleading 0.
    public function computeRubricTotal(Grade $grade): ?float
    {
        $any = false;
        $sum = 0.0;

        foreach ($grade->getRubricAnswers() as $answer) {
            if ($answer->isNotTested()) {
                $any = true;
                continue;
            }

            if (null !== $answer->getPointsAwarded()) {
                $any = true;
                $sum += max(0.0, min($answer->getQuestion()?->getMaxPoints() ?? 0.0, $answer->getPointsAwarded()));
            }
        }

        return $any ? round($sum, 2) : null;
    }

    // The 4 pastel grade bands (design's gradeColor()) as a CSS class name - see the matching
    // .cm-grade-band-* rules in assets/styles/app.css. Operates on an already-/20-normalized value.
    public function gradeColorClass(?float $normalizedValue): string
    {
        if (null === $normalizedValue) {
            return 'cm-grade-band-none';
        }

        return match (true) {
            $normalizedValue <= 5 => 'cm-grade-band-1',
            $normalizedValue <= 10 => 'cm-grade-band-2',
            $normalizedValue <= 15 => 'cm-grade-band-3',
            default => 'cm-grade-band-4',
        };
    }
}
