<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\LessonSession;

/**
 * Which slot of the day the « Séance du jour » and « Déroulé de séance » widgets show
 * (design/validated/tableau-virtuel.md, §6): the one under way, failing that the next one of the
 * day, failing that the last one that ended - a board opened after the lesson still shows it.
 *
 * And the one conversion between the two clocks of the platform: timetable slots are in DECIMAL
 * HOURS, the phases of a séance in MINUTES. It happens here, once, and nothing past this class sees
 * an hour.
 */
final class TodaySlots
{
    /**
     * @param list<LessonSession> $slots the day's slots, in any order
     */
    public static function current(array $slots, \DateTimeImmutable $now): ?LessonSession
    {
        usort($slots, static fn (LessonSession $a, LessonSession $b): int => self::start($a) <=> self::start($b));

        $next = null;
        $last = null;
        foreach ($slots as $slot) {
            $start = self::start($slot);
            $end = self::end($slot);
            if (null === $start || null === $end) {
                continue;
            }
            if ($start <= $now && $now < $end) {
                return $slot;
            }
            if ($start > $now) {
                $next ??= $slot;
            } else {
                $last = $slot;
            }
        }

        return $next ?? $last;
    }

    /**
     * A slot's length, from its decimal hours (« 1.5 ») to whole minutes; its two hours when the
     * length was left empty.
     */
    public static function minutes(LessonSession $slot): int
    {
        $length = $slot->getLength();
        if (null !== $length && is_numeric($length) && (float) $length > 0) {
            return (int) round((float) $length * 60);
        }

        $start = self::start($slot);
        $end = self::end($slot);

        return null === $start || null === $end ? 0 : intdiv(max(0, $end->getTimestamp() - $start->getTimestamp()), 60);
    }

    /**
     * A phase's duration, stored as a DECIMAL of minutes, as whole minutes - never under one.
     */
    public static function phaseMinutes(?string $duration): int
    {
        return null !== $duration && is_numeric($duration) ? max(1, (int) round((float) $duration)) : 1;
    }

    public static function start(LessonSession $slot): ?\DateTimeImmutable
    {
        return $slot->getStartAt();
    }

    public static function end(LessonSession $slot): ?\DateTimeImmutable
    {
        return $slot->getEndAt();
    }
}
