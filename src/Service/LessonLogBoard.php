<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The decisions behind the cahier de texte list: which week it opens on, and what each row's badge
 * says.
 *
 * Named after App\Service\StudentWorkBoard and for the same reason - the state rule of a screen
 * belongs in one place, not spread through the controller that renders it.
 *
 * Extracted out of App\Controller\LessonLogController. Both methods take plain values rather than
 * entities: the week logic is calendar arithmetic and the badge only ever looked at one field.
 */
final class LessonLogBoard
{
    /**
     * The week to show, always as its Monday.
     *
     * An explicit `?week=` wins outright, even on a week with no lesson - the teacher asked for it.
     * Otherwise the current week is used when it carries lessons, and when it does not - a holiday,
     * a stage period, an alternance week - the screen jumps forward to the next week that has some
     * rather than opening on nothing. Past the last lesson of the year there is nothing ahead, so
     * it falls back to the last week that had any.
     *
     * @param string       $requested        the raw `week` query parameter, any day of the week
     * @param list<string> $weeksWithLessons Monday of each week that carries a lesson, ascending
     */
    public function weekToDisplay(string $requested, array $weeksWithLessons, \DateTimeImmutable $today): \DateTimeImmutable
    {
        if ('' !== $requested) {
            try {
                return $this->weekStartOf(new \DateTimeImmutable($requested));
            } catch (\Exception) {
                // Unreadable parameter: fall back below rather than blowing up.
            }
        }

        $currentWeek = $this->weekStartOf($today);

        if ([] === $weeksWithLessons || \in_array($currentWeek->format('Y-m-d'), $weeksWithLessons, true)) {
            return $currentWeek;
        }

        $upcoming = array_filter(
            $weeksWithLessons,
            static fn (string $week): bool => $week > $currentWeek->format('Y-m-d'),
        );

        return new \DateTimeImmutable([] !== $upcoming ? reset($upcoming) : end($weeksWithLessons));
    }

    /**
     * The badge on one row, in a word.
     *
     * Only what was actually taught decides. The lesson log is the record of the lesson, and that is
     * the one section nothing else can stand in for; the before/after sections carry the work given,
     * which may legitimately be empty for a session - counting them would mark a perfectly kept log
     * as incomplete. Hence two states and no "partial".
     *
     * @param ?string $duringContent the "pendant" section's HTML, null when there is no log at all
     */
    public function stateOf(?string $duringContent): string
    {
        return '' === trim(strip_tags((string) $duringContent)) ? 'empty' : 'filled';
    }

    /**
     * The Monday a day belongs to, at midnight - the key the list groups its rows under, and the
     * shape every date handled here takes.
     */
    public function weekStartOf(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0)->modify('monday this week');
    }
}
