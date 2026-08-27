<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The unit of time an attendance statement covers - the week by default, the month for a class that
 * prefers a monthly pass (§4, decision 3).
 *
 * Nothing else changes between the two: the rate normalises. A month is simply one unit worth as
 * many weeks as it covers, which is why App\Entity\AttendanceStatement carries $weeksCovered rather
 * than the step itself.
 */
enum GameAttendanceStep: string
{
    case Week = 'week';
    case Month = 'month';

    public function labelKey(): string
    {
        return match ($this) {
            self::Week => 'gameAttendanceStepWeekLabel',
            self::Month => 'gameAttendanceStepMonthLabel',
        };
    }
}
