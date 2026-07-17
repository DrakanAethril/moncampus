<?php

namespace App\Enum;

// Who started a QuizAttempt - a student on their own (Initiale, the only case Phase 3 ever
// creates), or a teacher granting a retry (Relance) via the results screens (later phase) - see
// design/design_campus_manager/README.md's "Générateur de quiz" section, 1f/1p.
enum AttemptOrigin: string
{
    case Initiale = 'initiale';
    case Relance = 'relance';

    public function labelKey(): string
    {
        return match ($this) {
            self::Initiale => 'attemptOriginInitialeLabel',
            self::Relance => 'attemptOriginRelanceLabel',
        };
    }
}
