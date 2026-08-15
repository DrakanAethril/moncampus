<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Grade;
use App\Entity\SelfAssessment;

/**
 * Brings a student's estimate together with the teacher's grading
 * (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9, screens 5c and 5d).
 *
 * A single place decides what a gap is and what « juste » means, so that the student's comparison
 * screen and the teacher's tracking screen cannot tell two different stories.
 */
class SelfAssessmentComparator
{
    // Margin in points below which an estimate counts as accurate (the « justes à ±1 pt » counter of
    // 5d, the « juste » chip of 5c).
    public const float FAIR_MARGIN = 1.0;

    /**
     * The comparison screen only exists if the teacher shared their grading, if the estimate is
     * submitted, and if the grade is actually readable by the student - an evaluation with scheduled
     * visibility stays hidden until its deadline, self-assessment or not.
     */
    public function isComparisonReadable(Assignment $assignment, ?SelfAssessment $selfAssessment, ?Grade $grade, \DateTimeImmutable $now): bool
    {
        return $assignment->sharesTeacherGrade()
            && null !== $selfAssessment
            && $selfAssessment->isValidated()
            && null !== $grade
            && null !== $grade->getValue()
            && true === $assignment->getEvaluation()?->isVisibleAt($now);
    }

    public function gap(?float $estimated, ?float $graded): ?float
    {
        if (null === $estimated || null === $graded) {
            return null;
        }

        return round($estimated - $graded, 2);
    }

    /**
     * Accuracy at the grain of the total grade: the ±1 point tolerance of the « justes à ±1 pt »
     * counter (5d) and of the summary sentence (5c).
     */
    public function isFair(?float $gap): bool
    {
        return null !== $gap && abs($gap) <= self::FAIR_MARGIN;
    }

    /**
     * Accuracy at the grain of a question: here, no tolerance at all - the designs show « +0,5 » or
     * « −0,5 » rather than « juste » as soon as half a point separates the estimate from the
     * grading, and reserve « juste » for the exact estimate.
     */
    public function isExact(?float $gap): bool
    {
        return null !== $gap && 0.0 === round($gap, 2);
    }

    /**
     * The question-by-question detail of 5c: the student's estimate and the points actually awarded,
     * with their share of the maximum for the two progress bars.
     *
     * @return list<array{sectionName: string, label: string, maxPoints: float, estimated: ?float, graded: ?float, estimatedPercent: float, gradedPercent: float, gap: ?float}>
     */
    public function questionRows(Assignment $assignment, SelfAssessment $selfAssessment, ?Grade $grade): array
    {
        $gradedByQuestionId = [];
        foreach ($grade?->getRubricAnswers() ?? [] as $answer) {
            $gradedByQuestionId[$answer->getQuestion()?->getId()] = $answer->isNotTested() ? 0.0 : $answer->getPointsAwarded();
        }

        $rows = [];
        foreach ($assignment->getEvaluation()?->getRubricSections() ?? [] as $section) {
            foreach ($section->getQuestions() as $question) {
                $max = $question->getMaxPoints();
                $estimated = $selfAssessment->answerFor($question)?->getEstimatedPoints();
                $graded = $gradedByQuestionId[$question->getId()] ?? null;

                $rows[] = [
                    'sectionName' => $section->getName(),
                    'label' => $question->getLabel(),
                    'maxPoints' => $max,
                    'estimated' => $estimated,
                    'graded' => $graded,
                    'estimatedPercent' => $max > 0 && null !== $estimated ? round(($estimated / $max) * 100) : 0.0,
                    'gradedPercent' => $max > 0 && null !== $graded ? round(($graded / $max) * 100) : 0.0,
                    'gap' => $this->gap($estimated, $graded),
                ];
            }
        }

        return $rows;
    }

    /**
     * Compteurs du bandeau enseignant (5d).
     *
     * @param list<array{selfAssessment: ?SelfAssessment, gap: ?float}> $rows
     *
     * @return array{submitted: int, total: int, averageGap: ?float, fairCount: int}
     */
    public function summary(array $rows): array
    {
        $gaps = array_values(array_filter(array_column($rows, 'gap'), static fn (?float $gap): bool => null !== $gap));

        return [
            'submitted' => \count(array_filter($rows, static fn (array $row): bool => true === $row['selfAssessment']?->isValidated())),
            'total' => \count($rows),
            'averageGap' => [] === $gaps ? null : round(array_sum($gaps) / \count($gaps), 2),
            'fairCount' => \count(array_filter($gaps, fn (float $gap): bool => $this->isFair($gap))),
        ];
    }
}
