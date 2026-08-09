<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Grade;
use App\Entity\SelfAssessment;

/**
 * Rapproche l'estimation d'un étudiant de la notation de l'enseignant
 * (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9, écrans 5c et 5d).
 *
 * Un seul endroit décide de ce qu'est un écart et de ce qu'est « juste », pour que l'écran comparé
 * de l'étudiant et le suivi de l'enseignant ne puissent pas raconter deux histoires différentes.
 */
class SelfAssessmentComparator
{
    // Marge en points sous laquelle une estimation est comptée comme juste (compteur « justes à
    // ±1 pt » du 5d, chip « juste » du 5c).
    public const float FAIR_MARGIN = 1.0;

    /**
     * L'écran comparé n'existe que si l'enseignant a partagé sa notation, que l'estimation est
     * validée, et que la note est effectivement lisible par l'étudiant - une évaluation à
     * visibilité programmée reste masquée jusqu'à son échéance, autoévaluation ou non.
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
     * Justesse au grain de la note totale : la tolérance de ±1 point du compteur « justes à ±1 pt »
     * (5d) et de la phrase de synthèse (5c).
     */
    public function isFair(?float $gap): bool
    {
        return null !== $gap && abs($gap) <= self::FAIR_MARGIN;
    }

    /**
     * Justesse au grain d'une question : là, aucune tolérance - les créas affichent « +0,5 » ou
     * « −0,5 » plutôt que « juste » dès qu'un demi-point sépare l'estimation de la notation, et ne
     * réservent « juste » qu'à l'estimation exacte.
     */
    public function isExact(?float $gap): bool
    {
        return null !== $gap && 0.0 === round($gap, 2);
    }

    /**
     * Le détail question par question du 5c : l'estimation de l'étudiant et les points réellement
     * attribués, avec leur part du maximum pour les deux barres de progression.
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
