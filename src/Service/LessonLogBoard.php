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
     * The content is HugeRTE's HTML, and an "emptied" paragraph is not an empty string there: the
     * editor leaves `<p>&nbsp;</p>` behind when a teacher types something and deletes it. Entities
     * are therefore decoded before the emptiness test, and the whitespace that test ignores has to
     * include the non-breaking space itself - which trim() does not touch, being multi-byte.
     *
     * @param ?string $duringContent the "pendant" section's HTML, null when there is no log at all
     */
    public function stateOf(?string $duringContent): string
    {
        $text = html_entity_decode(strip_tags((string) $duringContent), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // \x{00A0} non-breaking space, \x{200B} zero-width space, \x{FEFF} byte-order mark - the
        // three invisibles a paste from Word or a browser can leave in an otherwise empty field.
        return '' === preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $text) ? 'empty' : 'filled';
    }

    /**
     * The tag on a row of the period screen, in one of three words.
     *
     * The two-state badge above stays the authority on « rempli »: only the account of the lesson
     * makes a log kept. What the third state adds is the difference between a séance nobody has
     * touched and one where the work was given but the lesson not yet written up - a teacher
     * scanning the week wants those two apart, and the older screen could only call both « vide ».
     *
     * @param bool $hasExtras whether a document or an assignment hangs off the séance
     */
    public function sessionStateOf(?string $before, ?string $during, ?string $after, bool $hasExtras): string
    {
        if ('filled' === $this->stateOf($during)) {
            return 'filled';
        }

        $started = $hasExtras
            || 'filled' === $this->stateOf($before)
            || 'filled' === $this->stateOf($after);

        return $started ? 'partial' : 'empty';
    }

    /**
     * The same three states read on one part of the cahier de texte - the dot of the preview's
     * « avant / pendant / après » rows.
     *
     * @param bool $hasExtras whether a document or an assignment hangs off that part
     */
    public function sectionStateOf(?string $content, bool $hasExtras): string
    {
        if ('filled' === $this->stateOf($content)) {
            return 'filled';
        }

        return $hasExtras ? 'partial' : 'empty';
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
