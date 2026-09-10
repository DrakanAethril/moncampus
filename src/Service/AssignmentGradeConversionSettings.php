<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Topic;
use App\Enum\EvaluationModality;
use App\Enum\EvaluationNature;
use App\Enum\EvaluationStatus;
use App\Enum\EvaluationType;

/**
 * What the « Convertir en note » modal answers: the evaluation the carnet de notes is about to
 * receive, before any grade is written on it.
 *
 * The same fields the carnet's own creation form asks for (App\Form\EvaluationFormType), minus the
 * détail du barème: an evaluation born of a quiz is marked by the quiz, and a rubric would offer
 * columns nobody will ever fill.
 */
final class AssignmentGradeConversionSettings
{
    public function __construct(
        public readonly Topic $topic,
        public readonly string $name,
        public readonly \DateTimeImmutable $date,
        public readonly EvaluationType $type,
        public readonly ?EvaluationNature $nature,
        public readonly EvaluationModality $modality,
        public readonly EvaluationStatus $status,
        public readonly float $scale,
        public readonly float $coefficient,
        public readonly bool $countsOutOf20,
        public readonly ?\DateTimeImmutable $visibleAt,
    ) {
    }
}
