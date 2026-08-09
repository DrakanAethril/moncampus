<?php

declare(strict_types=1);

namespace App\Enum;

// Prévue/Surprise (design's "Statut" card) - purely informational, doesn't affect grading/averaging.
enum EvaluationStatus: string
{
    case Planned = 'planned';
    case Surprise = 'surprise';

    public function labelKey(): string
    {
        return match ($this) {
            self::Planned => 'evaluationStatusPlannedLabel',
            self::Surprise => 'evaluationStatusSurpriseLabel',
        };
    }
}
