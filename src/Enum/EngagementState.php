<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a declared engagement stands (§6).
 *
 * **Nothing is credited before validation**, and that is the whole shape of the family: the
 * automatic signals pay a little for what the machine can see, and the rest is paid for what an
 * adult has read. A refusal is motivated and read by the student, exactly like a malus.
 *
 * A refused declaration **stays in the queue, struck through**, rather than disappearing: it is what
 * stops the same thing from being re-filed three times in the hope of a different reviewer.
 */
enum EngagementState: string
{
    case Filed = 'filed';
    case Validated = 'validated';
    case Refused = 'refused';

    public function labelKey(): string
    {
        return match ($this) {
            self::Filed => 'engagementStateFiledLabel',
            self::Validated => 'engagementStateValidatedLabel',
            self::Refused => 'engagementStateRefusedLabel',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Filed => 'gold',
            self::Validated => 'green',
            self::Refused => 'gray',
        };
    }

    public function isDecided(): bool
    {
        return self::Filed !== $this;
    }
}
