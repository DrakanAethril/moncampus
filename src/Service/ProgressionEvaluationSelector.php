<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Evaluation;

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
}
