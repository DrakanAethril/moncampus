<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\LessonSession;
use App\Service\LessonSessionEventFormatter;

/**
 * Turns a set of LessonSessions into the iCalendar document a subscribed agenda fetches, applying
 * the legend filter the reader had on screen at the moment they took the link.
 *
 * **The filter can only ever remove.** It is read off an unsigned query parameter, so anybody
 * holding the link can rewrite it - and that is harmless by construction: hiding a legend narrows
 * what the owner already had the right to see, and no value of it can widen the feed. What decides
 * the perimeter is the token's owner, checked by App\Controller\TimetableCalendarController through
 * the ordinary access rules, never this list.
 *
 * @phpstan-import-type IcsEvent from IcsWriter
 */
class TimetableIcsBuilder
{
    public function __construct(
        private readonly IcsWriter $writer,
        private readonly LessonSessionEventFormatter $eventFormatter,
    ) {
    }

    /**
     * @param list<LessonSession> $sessions
     * @param list<string>        $hiddenLegendKeys legend keys the reader had toggled off, in the
     *                                             same vocabulary the swatches use - Option ids
     *                                             (or `default`) on a formation's calendar, Program
     *                                             ids on a teacher's personal one
     */
    public function build(string $calendarName, array $sessions, array $hiddenLegendKeys, bool $legendByProgram, string $uidDomain): string
    {
        $events = [];

        foreach ($sessions as $session) {
            if (\in_array($this->eventFormatter->legendKey($session, $legendByProgram), $hiddenLegendKeys, true)) {
                continue;
            }

            $events[] = $this->event($session, $uidDomain);
        }

        return $this->writer->write($calendarName, $events);
    }

    /**
     * @return IcsEvent
     */
    private function event(LessonSession $session, string $uidDomain): array
    {
        $day = $session->getDay();

        return [
            // Stable across every fetch, so a séance whose hour moves is *replaced* in the
            // subscriber's agenda rather than added a second time next to the old one. It is the
            // séance's own id that makes it stable - not its position, not its title.
            'uid' => \sprintf('lesson-session-%d@%s', (int) $session->getId(), $uidDomain),
            'start' => $this->at($day, $session->getStartHour()),
            'end' => $this->at($day, $session->getEndHour()),
            'summary' => $session->getDisplayName(),
            'location' => $session->getClassRoom()?->getName(),
            'description' => $this->description($session),
        ];
    }

    // The séance's day carries the date and its start/end hours carry the time, in two separate
    // columns - so the moment is built here, in Europe/Paris, which is the frame the on-screen
    // calendar already reads them in (lesson_timetable_controller.js's `timeZone`). IcsWriter then
    // converts to UTC, and that is what resolves summer time date by date.
    //
    // Recomposed from the two formatted halves rather than by moving $day's own timezone: both
    // columns are hydrated in PHP's default zone, and shifting a midnight across a zone boundary is
    // how a Monday séance lands on the Sunday.
    private function at(\DateTimeImmutable $day, \DateTimeInterface $hour): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            $day->format('Y-m-d').' '.$hour->format('H:i:s'),
            new \DateTimeZone('Europe/Paris'),
        );
    }

    // What the on-screen event prints under its title, joined the same way - formation, matière,
    // enseignant, type, options. An agenda gives a séance one line and a detail panel, and the
    // panel is the only place any of this can go.
    private function description(LessonSession $session): ?string
    {
        $teacher = $session->getTeacher();

        $parts = array_filter([
            $session->getProgram()->getDisplayShortName(),
            $session->getTopic()?->getName(),
            null !== $teacher ? ($teacher->getDisplayName() ?? $teacher->getUsername()) : null,
            $session->getLessonType()?->getName(),
        ]);

        return [] === $parts ? null : implode(' · ', $parts);
    }
}
