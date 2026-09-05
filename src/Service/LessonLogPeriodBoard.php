<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the cahier de texte's period screen has decided before it renders anything: which of the two
 * lists the left column shows, which week it shows, which séance is previewed, and what is unfolded
 * (design/design_handoff_cahier_de_texte_seances).
 *
 * Named after LessonLogBoard and StudentWorkBoard, and here for the same reason: the state rule of a
 * screen belongs in one place. Every method takes plain values - an id, a `Y-m-d`, a mode - because
 * that is all any of them looks at, which is also what makes the incoming-link rules testable
 * without a timetable.
 *
 * The one thing it deliberately does NOT do is jump to a week that has lessons, unlike
 * LessonLogBoard::weekToDisplay(): this screen answers « the current week », and a week without
 * séance is an answer rather than a miss. It is the whole teacher's timetable here, not one class's,
 * so an empty week means a holiday - and moving the period without being asked would make the ‹ ›
 * arrows unusable.
 *
 * @phpstan-type SessionRow array{id: int, classId: int, day: string}
 */
final class LessonLogPeriodBoard
{
    /** Accordion of classes, one class open at a time - the default the screen opens on. */
    public const string MODE_CLASS = 'class';

    /** Séances one after the other, grouped by day, no class grouping. */
    public const string MODE_CHRONOLOGICAL = 'chronological';

    /**
     * Which list the left column shows.
     *
     * The order is « what was clicked, then what was asked for in the link, then what the last
     * visit left behind ». A date outranks a class because the two are not exclusive - the class
     * only says which accordion opens - and a day is precisely what the chronological list is for.
     *
     * @param ?string $requested  the `view` parameter, i.e. the segmented control
     * @param ?int    $classId    the `class` parameter of an incoming link
     * @param ?string $date       the `date` parameter of an incoming link
     * @param ?string $remembered the mode this user last chose
     */
    public function viewMode(?string $requested, ?int $classId, ?string $date, ?string $remembered): string
    {
        if (\in_array($requested, [self::MODE_CLASS, self::MODE_CHRONOLOGICAL], true)) {
            return $requested;
        }

        if (null !== $date) {
            return self::MODE_CHRONOLOGICAL;
        }

        if (null !== $classId) {
            return self::MODE_CLASS;
        }

        return self::MODE_CHRONOLOGICAL === $remembered ? self::MODE_CHRONOLOGICAL : self::MODE_CLASS;
    }

    /**
     * The Monday of the week on display, at midnight.
     *
     * An explicit `week` wins over an incoming `date`, and that order is what makes the ‹ › arrows
     * work: they move the week while the date that opened the screen stays in the URL, and the
     * period would otherwise spring back to it at every click.
     */
    public function weekStart(?string $week, ?string $date, \DateTimeImmutable $today): \DateTimeImmutable
    {
        foreach ([$week, $date] as $candidate) {
            if (null === $candidate || '' === $candidate) {
                continue;
            }

            try {
                return $this->mondayOf(new \DateTimeImmutable($candidate));
            } catch (\Exception) {
                // Unreadable parameter: try the next one, then today - never a 500 on a hand-typed URL.
            }
        }

        return $this->mondayOf($today);
    }

    /**
     * The séance the preview describes.
     *
     * `$requested` is checked against the period rather than trusted: the ‹ › arrows carry the
     * `seance` of the week one has just left, and it no longer names anything on screen.
     *
     * A date that carries no séance selects nothing on purpose - the screen says so
     * (isDateWithoutSession()) rather than previewing an unrelated one.
     *
     * @param list<SessionRow> $rows by day, then by hour
     */
    public function selectedSession(array $rows, ?int $requested, ?int $classId, ?string $date): ?int
    {
        foreach ($rows as $row) {
            if ($row['id'] === $requested) {
                return $requested;
            }
        }

        if (null !== $date) {
            return $this->firstIdWhere($rows, static fn (array $row): bool => $row['day'] === $date);
        }

        if (null !== $classId) {
            return $this->firstIdWhere($rows, static fn (array $row): bool => $row['classId'] === $classId);
        }

        return $rows[0]['id'] ?? null;
    }

    /**
     * The one class unfolded in the by-class list - a single value, since opening a class closes
     * the previous one.
     *
     * A class with no séance this week has no header in the list at all, so it cannot be the one
     * open however loudly the link asks for it.
     *
     * @param list<SessionRow> $rows
     */
    public function openClass(array $rows, ?int $classId, ?int $selectedId): ?int
    {
        foreach ($rows as $row) {
            if ($row['classId'] === $classId) {
                return $classId;
            }
        }

        foreach ($rows as $row) {
            if ($row['id'] === $selectedId) {
                return $row['classId'];
            }
        }

        return $rows[0]['classId'] ?? null;
    }

    /**
     * The days unfolded in the chronological list. A list rather than a single value because days
     * open and close independently, unlike classes - but only one is unfolded on arrival.
     *
     * @param list<SessionRow> $rows
     *
     * @return list<string>
     */
    public function openDays(array $rows, ?string $date, ?int $selectedId): array
    {
        foreach ($rows as $row) {
            if ($row['day'] === $date) {
                return [$date];
            }
        }

        foreach ($rows as $row) {
            if ($row['id'] === $selectedId) {
                return [$row['day']];
            }
        }

        return [] === $rows ? [] : [$rows[0]['day']];
    }

    /**
     * Whether the day an incoming link named is one the viewer has no séance on. The screen says so
     * in a sentence: landing on a silent list would otherwise read as a broken link rather than as
     * a free day.
     *
     * @param list<SessionRow> $rows
     */
    public function isDateWithoutSession(array $rows, ?string $date): bool
    {
        if (null === $date) {
            return false;
        }

        foreach ($rows as $row) {
            if ($row['day'] === $date) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<SessionRow>      $rows
     * @param callable(SessionRow): bool $matches
     */
    private function firstIdWhere(array $rows, callable $matches): ?int
    {
        foreach ($rows as $row) {
            if ($matches($row)) {
                return $row['id'];
            }
        }

        return null;
    }

    private function mondayOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify('monday this week');
    }
}
