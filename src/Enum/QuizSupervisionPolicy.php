<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the application does with a page exit during a supervised évaluation.
 *
 * The three levels differ in *when* they act, not in how hard they hit: `Log` documents afterwards,
 * `Warn` speaks while the student is still deciding, `Autosubmit` hands the copy in. None of them
 * touches a mark - see App\Service\QuizSupervisionAssessor and the design's §5.
 *
 * `Warn` is the default because it is the only one that acts *during*: a student who finds out at
 * question 4 that their exits are counted does not start again at question 5. The other two only
 * ever describe what has already happened.
 */
enum QuizSupervisionPolicy: string
{
    case Log = 'log';
    case Warn = 'warn';
    case Autosubmit = 'autosubmit';

    public function labelKey(): string
    {
        return match ($this) {
            self::Log => 'quizSupervisionPolicyLogLabel',
            self::Warn => 'quizSupervisionPolicyWarnLabel',
            self::Autosubmit => 'quizSupervisionPolicyAutosubmitLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::Log => 'quizSupervisionPolicyLogDescription',
            self::Warn => 'quizSupervisionPolicyWarnDescription',
            self::Autosubmit => 'quizSupervisionPolicyAutosubmitDescription',
        };
    }

    /** True when the student is told, on screen, that their exits are being counted. */
    public function warnsStudent(): bool
    {
        return self::Log !== $this;
    }
}
