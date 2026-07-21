<?php

namespace App\Enum;

// Individuelle/En groupe (design's "Modalité" card). A dedicated enum, not App\Entity\Modality
// (that entity is the establishment-wide internship/schedule modality list - distance/présentiel
// etc - an unrelated concept that happens to share the English word).
enum EvaluationModality: string
{
    case Individual = 'individual';
    case Group = 'group';

    public function labelKey(): string
    {
        return match ($this) {
            self::Individual => 'evaluationModalityIndividualLabel',
            self::Group => 'evaluationModalityGroupLabel',
        };
    }
}
