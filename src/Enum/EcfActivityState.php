<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where one activity-type of a booklet stands, as the follow-up card and the overview show it.
 * Read off the activity by App\Service\Ecf\EcfMastery, never stored.
 */
enum EcfActivityState: string implements HasBadge
{
    /** Nothing written yet. */
    case ToFill = 'to_fill';

    /** Something written, no visa on the part that decides. */
    case InProgress = 'in_progress';

    /** The last signed part says « satisfait »: the activity is mastered. */
    case Satisfied = 'satisfied';

    /** The last signed part says « non satisfait »: complementary evaluations are expected. */
    case NotSatisfied = 'not_satisfied';

    public function labelKey(): string
    {
        return match ($this) {
            self::ToFill => 'ecfActivityStateToFillLabel',
            self::InProgress => 'ecfActivityStateInProgressLabel',
            self::Satisfied => 'ecfActivityStateSatisfiedLabel',
            self::NotSatisfied => 'ecfActivityStateNotSatisfiedLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::ToFill => BadgeTone::Gray,
            self::InProgress => BadgeTone::Blue,
            self::Satisfied => BadgeTone::Green,
            self::NotSatisfied => BadgeTone::Gold,
        };
    }
}
