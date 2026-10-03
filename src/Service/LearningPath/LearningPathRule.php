<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

use App\Enum\LearningPathStepState;

/**
 * The rule of a learning path, as a pure function on primitives
 * (design/validated/cours-en-ligne.md, §10):
 *
 * > A step is open when every validation quiz placed before it is validated. A quiz is validated
 * > when the best score reaches its threshold.
 *
 * Everything else follows from reading it literally:
 *
 * - **validation quizzes are optional** - with no quiz before it a step is open, so a path with no
 *   quiz at all is a run of courses, every one of them open from the start;
 * - a course closes nothing: having opened a page proves nothing about what was read. It is *done*
 *   once opened, and that is all;
 * - **a step somebody already opened stays open**, whatever the author changes before it (a
 *   threshold raised, a quiz inserted) - the rule the access conditions of the platform hold;
 * - validation is **read, never stored**: the best score against today's threshold. Lowering a
 *   threshold validates at once those who already reached it;
 * - an unavailable step - its course taken offline, its quiz deleted - is skipped: it is neither a
 *   wall nor something to do.
 *
 * It deliberately does not reuse App\Service\AccessConditionEvaluator: that tree names objects of a
 * class (a launched quiz, a travail, a séance), and a path has no class. What is taken from it is
 * the behaviour - a locked row that says why, a begun object kept open, the check repeated at the
 * door - and that fits in this one function because a path is an ordered list.
 *
 * @phpstan-type StepFact array{available: bool, quiz: bool, threshold: int, best: ?int, opened: bool}
 * @phpstan-type StepVerdict array{state: LearningPathStepState, lockedBy: ?int}
 */
final class LearningPathRule
{
    /**
     * @param list<StepFact> $steps in the order they are followed
     *
     * @return list<StepVerdict> one per step; `lockedBy` is the index of the quiz that closes it
     */
    public static function evaluate(array $steps): array
    {
        $verdicts = [];
        // The first validation quiz not passed yet: everything after it is closed by it.
        $gate = null;

        foreach ($steps as $index => $step) {
            if (!$step['available']) {
                $verdicts[] = ['state' => LearningPathStepState::Unavailable, 'lockedBy' => null];

                continue;
            }

            $validated = $step['quiz'] && null !== $step['best'] && $step['best'] >= $step['threshold'];
            $done = $step['quiz'] ? $validated : $step['opened'];
            $reachable = null === $gate || $step['opened'];

            $verdicts[] = [
                'state' => !$reachable ? LearningPathStepState::Locked : ($done ? LearningPathStepState::Done : LearningPathStepState::Open),
                'lockedBy' => $reachable ? null : $gate,
            ];

            if ($step['quiz'] && !$validated && null === $gate) {
                $gate = $index;
            }
        }

        return $verdicts;
    }

    /**
     * Finished: there is something to do, and all of it is done.
     *
     * @param list<StepVerdict> $verdicts
     */
    public static function isCompleted(array $verdicts): bool
    {
        $toDo = 0;
        foreach ($verdicts as $verdict) {
            if (LearningPathStepState::Unavailable === $verdict['state']) {
                continue;
            }
            if (LearningPathStepState::Done !== $verdict['state']) {
                return false;
            }
            ++$toDo;
        }

        return $toDo > 0;
    }
}
