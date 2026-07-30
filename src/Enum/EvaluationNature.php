<?php

namespace App\Enum;

// The pedagogical nature of an evaluation, as used by the Progression pédagogique module
// (design/design_handoff_progression/README.md §1). Deliberately separate from EvaluationType
// (written/oral/practical, the *format*) and nullable on App\Entity\Evaluation: evaluations
// created before this module existed - and any created straight from the Carnet de notes without
// caring - simply have no nature, which is a valid state, not a missing value to backfill.
enum EvaluationNature: string
{
    case Diagnostic = 'diagnostic';
    case Formative = 'formative';
    case Summative = 'summative';

    public function labelKey(): string
    {
        return match ($this) {
            self::Diagnostic => 'evaluationNatureDiagnosticLabel',
            self::Formative => 'evaluationNatureFormativeLabel',
            self::Summative => 'evaluationNatureSummativeLabel',
        };
    }

    // The single letter used by the "D · F · S" counters on the progression list (3a) and rail (5a).
    public function initial(): string
    {
        return match ($this) {
            self::Diagnostic => 'D',
            self::Formative => 'F',
            self::Summative => 'S',
        };
    }

    // Design token suffix - drives .cm-prog-eval--{slug} in app.css (blue / gold / red).
    public function slug(): string
    {
        return $this->value;
    }
}
