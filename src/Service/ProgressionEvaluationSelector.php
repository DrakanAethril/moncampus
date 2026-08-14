<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\ProgressionSequence;

/**
 * Which of a matière's evaluations the progression screen still offers to attach to a séquence.
 *
 * Three conditions, none of them visible from the screen itself:
 * - it declares a nature (diagnostic / formative / summative) - that is what makes it placeable at all;
 * - it is not already attached to a séquence;
 * - it has not been deactivated.
 *
 * Extracted out of App\Controller\ProgressionController, where the rule sat among the screen's
 * other view-model building and could only be checked by clicking through.
 */
final class ProgressionEvaluationSelector
{
    /**
     * @param iterable<Evaluation> $evaluations a matière's own, in any order
     *
     * @return list<Evaluation> oldest first; an evaluation with no date yet still comes back, since
     *                          hiding it would make it unreachable from this screen
     */
    public function outOfSequence(iterable $evaluations): array
    {
        $selected = [];

        foreach ($evaluations as $evaluation) {
            if (null !== $evaluation->getNature()
                && null === $evaluation->getProgressionSequence()
                && null === $evaluation->getInactiveDate()
            ) {
                $selected[] = $evaluation;
            }
        }

        usort($selected, static fn (Evaluation $a, Evaluation $b): int => $a->getDate() <=> $b->getDate());

        return $selected;
    }

    /**
     * The other half of the same rule: what one séquence of the progression carries.
     *
     * Attaching an evaluation to a séquence used to make it vanish - outOfSequence() dropped it and
     * nothing else listed it - so it could no longer be edited or removed from the module that
     * created it. The placement screen shows these, which is why the date order matters here too.
     *
     * @param iterable<Evaluation> $evaluations a matière's own, in any order
     *
     * @return list<Evaluation> oldest first
     */
    public function forSequence(iterable $evaluations, ProgressionSequence $sequence): array
    {
        $selected = [];

        foreach ($evaluations as $evaluation) {
            if ($evaluation->getProgressionSequence() === $sequence
                && null === $evaluation->getInactiveDate()
            ) {
                $selected[] = $evaluation;
            }
        }

        usort($selected, static fn (Evaluation $a, Evaluation $b): int => $a->getDate() <=> $b->getDate());

        return $selected;
    }
}
