<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

use App\Entity\LearningPath;
use App\Entity\LearningPathEnrollment;
use App\Entity\LearningPathQuizAttempt;
use App\Entity\LearningPathStepVisit;
use App\Repository\LearningPathEnrollmentRepository;
use App\Repository\LearningPathQuizAttemptRepository;
use App\Repository\LearningPathStepVisitRepository;

/**
 * The learning-path authority the screens call (design/validated/cours-en-ligne.md, §10): « where
 * does this person stand on this path », in the lineage of App\Service\StudentWorkBoard.
 *
 * The decision is App\Service\LearningPath\LearningPathRule's, a pure function. What this adds is
 * the facts - which steps were opened, which scores were made - loaded once per screen: one person
 * for their own plan, a whole path's followers for its author's follow-up (forPath()), in two
 * queries either way.
 *
 * Nothing is cached across people, and nothing is stored: two followers read two different plans,
 * and a threshold moved by the author changes the reading at the next display.
 */
class LearningPathBoard
{
    public function __construct(
        private readonly LearningPathEnrollmentRepository $enrollments,
        private readonly LearningPathStepVisitRepository $visits,
        private readonly LearningPathQuizAttemptRepository $attempts,
    ) {
    }

    /**
     * The path as one follower sees it - or as somebody who has not started it does, when
     * `$enrollment` is null: nothing opened, nothing scored.
     */
    public function progress(LearningPath $path, ?LearningPathEnrollment $enrollment): LearningPathProgress
    {
        if (null === $enrollment) {
            return $this->read($path, null, [], []);
        }

        return $this->read($path, $enrollment, $this->visits->findForEnrollments([$enrollment]), $this->attempts->findForEnrollments([$enrollment]));
    }

    /**
     * Everybody following the path, each with their own reading - the author's follow-up.
     *
     * @return list<LearningPathProgress> in the order they started
     */
    public function forPath(LearningPath $path): array
    {
        $enrollments = $this->enrollments->findForPath($path);

        $visits = [];
        foreach ($this->visits->findForEnrollments($enrollments) as $visit) {
            $visits[(int) $visit->getEnrollment()->getId()][] = $visit;
        }

        $attempts = [];
        foreach ($this->attempts->findForEnrollments($enrollments) as $attempt) {
            $attempts[(int) $attempt->getEnrollment()->getId()][] = $attempt;
        }

        return array_map(
            fn (LearningPathEnrollment $enrollment): LearningPathProgress => $this->read($path, $enrollment, $visits[(int) $enrollment->getId()] ?? [], $attempts[(int) $enrollment->getId()] ?? []),
            $enrollments,
        );
    }

    /**
     * @param list<LearningPathStepVisit>   $visits
     * @param list<LearningPathQuizAttempt> $attempts oldest first
     */
    private function read(LearningPath $path, ?LearningPathEnrollment $enrollment, array $visits, array $attempts): LearningPathProgress
    {
        $openedAt = [];
        foreach ($visits as $visit) {
            $openedAt[(int) $visit->getStep()->getId()] = $visit->getOpenedAt();
        }

        $byStep = [];
        foreach ($attempts as $attempt) {
            $byStep[(int) $attempt->getStep()->getId()][] = $attempt;
        }

        $steps = $path->orderedSteps();
        $facts = [];
        $best = [];

        foreach ($steps as $step) {
            $stepId = (int) $step->getId();
            $scores = array_filter(
                array_map(static fn (LearningPathQuizAttempt $attempt): ?int => $attempt->isFinished() ? $attempt->getScorePercent() : null, $byStep[$stepId] ?? []),
                static fn (?int $score): bool => null !== $score,
            );
            $best[$stepId] = [] === $scores ? null : max($scores);

            $facts[] = [
                'available' => $step->isAvailable(),
                'quiz' => $step->isQuiz(),
                'threshold' => $step->getPassPercent(),
                'best' => $best[$stepId],
                // A quiz somebody has an attempt on was opened, whether or not the visit was written.
                'opened' => isset($openedAt[$stepId]) || isset($byStep[$stepId]),
            ];
        }

        $verdicts = LearningPathRule::evaluate($facts);
        $views = [];

        foreach ($steps as $index => $step) {
            $stepId = (int) $step->getId();
            $lockedBy = $verdicts[$index]['lockedBy'];

            $views[] = new LearningPathStepView(
                $step,
                $index + 1,
                $verdicts[$index]['state'],
                null === $lockedBy ? null : ($steps[$lockedBy] ?? null),
                $openedAt[$stepId] ?? null,
                $byStep[$stepId] ?? [],
                $best[$stepId],
            );
        }

        return new LearningPathProgress($enrollment, $views, LearningPathRule::isCompleted($verdicts));
    }
}
