<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

/**
 * R4 of the school-year import (design/validated/school-year-import.md): a date of the source year
 * is moved by a **whole number of weeks** - the gap between the two years' starts, forward or
 * backward - so that a Monday stays a Monday, then brought back inside the target year when it falls
 * out of it. A formation's own dates are not moved: it takes the target year's.
 *
 * Pure, on DateTimeImmutable only; the time of day is kept, so a deadline at 18:00 stays at 18:00.
 */
final readonly class SchoolYearDateShift
{
    private function __construct(
        public int $weeks,
        private \DateTimeImmutable $targetStart,
        private \DateTimeImmutable $targetEnd,
    ) {
    }

    public static function between(\DateTimeImmutable $sourceStart, \DateTimeImmutable $targetStart, \DateTimeImmutable $targetEnd): self
    {
        return new self(self::weeks($sourceStart, $targetStart), $targetStart, $targetEnd);
    }

    /** round((to - from) / 7 days), on calendar days - the time of day plays no part. */
    public static function weeks(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->format('%r%a');

        return (int) round($days / 7);
    }

    public function shift(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return 0 === $this->weeks ? $date : $date->modify(\sprintf('%+d weeks', $this->weeks));
    }

    /** The date, or the bound of the target year it went past - compared by day, the time kept. */
    public function clamp(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $day = $date->format('Y-m-d');
        $bound = match (true) {
            $day < $this->targetStart->format('Y-m-d') => $this->targetStart,
            $day > $this->targetEnd->format('Y-m-d') => $this->targetEnd,
            default => null,
        };
        if (null === $bound) {
            return $date;
        }

        return $date->setDate((int) $bound->format('Y'), (int) $bound->format('n'), (int) $bound->format('j'));
    }

    public function move(\DateTimeImmutable $date): ShiftedDate
    {
        $shifted = $this->shift($date);
        $clamped = $this->clamp($shifted);

        return new ShiftedDate($clamped, $clamped != $shifted);
    }

    /**
     * Both bounds of a period. One that leaves the year on one side is brought back on that side; a
     * period whose two bounds leave the year on the same side lands on that bound and is flagged
     * `outside` - it is created all the same and somebody has to read it.
     */
    public function moveRange(\DateTimeImmutable $start, \DateTimeImmutable $end): ShiftedRange
    {
        $from = $this->move($start);
        $to = $this->move($end);
        $first = $this->targetStart->format('Y-m-d');
        $last = $this->targetEnd->format('Y-m-d');
        $shiftedStart = $this->shift($start)->format('Y-m-d');
        $shiftedEnd = $this->shift($end)->format('Y-m-d');
        $outside = ($shiftedStart > $last && $shiftedEnd > $last) || ($shiftedStart < $first && $shiftedEnd < $first);

        return new ShiftedRange($from->date, $to->date, $from->clamped || $to->clamped, $outside);
    }
}
