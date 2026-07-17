<?php

namespace App\Enum;

// Concluded state of a QuizAttempt - see App\Entity\QuizAttempt's class docblock. Null (not this
// enum) is how "still in progress" is represented on QuizAttempt::$status; only reached once the
// attempt is finalized (submitted normally, or lazily closed once past its time window - same
// "compute live, no cron" pattern as Assignment::isLate()).
enum AttemptStatus: string
{
    case Termine = 'termine';
    case Interrompu = 'interrompu';

    public function labelKey(): string
    {
        return match ($this) {
            self::Termine => 'attemptStatusTermineLabel',
            self::Interrompu => 'attemptStatusInterrompuLabel',
        };
    }
}
