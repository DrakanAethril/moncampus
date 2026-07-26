<?php

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\Option;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Maps a LessonSession to the FullCalendar event JSON shape, shared between the editable
 * timetable tab (ProgramTimetableSettingsController), the read-only timetable page
 * (ProgramController::timetable()), and the teacher's personal cross-Program timetable
 * (TeacherTimetableController) so all three feeds stay in sync.
 */
class LessonSessionEventFormatter
{
    // Falls back to the site's Tabler primary color (rather than a hardcoded hex) so it keeps
    // tracking the theme if it's ever restyled - see templates/program/_timetable_legend.html.twig
    // for the matching legend swatch.
    private const DEFAULT_COLOR = 'var(--tblr-primary)';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly NameColorGenerator $colorGenerator,
    ) {
    }

    /**
     * @param bool $editable       Whether to include an edit URL (staff-facing feed only)
     * @param bool $colorByProgram Color/legend-key by formation (generated from the Program's
     *                             name) instead of by Option - only
     *                             App\Controller\TeacherTimetableController's cross-Program
     *                             personal feed uses this: a single Program's own calendar has
     *                             only one formation, so Option remains the meaningful way to
     *                             tell its sessions apart there.
     */
    public function format(LessonSession $session, bool $editable, bool $colorByProgram = false): array
    {
        $day = $session->getDay();
        $start = $day->setTime((int) $session->getStartHour()->format('H'), (int) $session->getStartHour()->format('i'));
        $end = $day->setTime((int) $session->getEndHour()->format('H'), (int) $session->getEndHour()->format('i'));

        $event = [
            'id' => $session->getId(),
            'title' => $session->getDisplayName(),
            'start' => $start->format('Y-m-d\TH:i:s'),
            'end' => $end->format('Y-m-d\TH:i:s'),
            'backgroundColor' => $colorByProgram ? $this->colorGenerator->generate($session->getProgram()->getShortName()) : $this->optionColor($session),
            'extendedProps' => [
                'teacher' => null !== $session->getTeacher() ? ($session->getTeacher()->getDisplayName() ?? $session->getTeacher()->getUsername()) : null,
                'classRoom' => $session->getClassRoom()?->getName(),
                'lessonType' => $session->getLessonType()?->getName(),
                'options' => $this->optionsLabel($session),
                // Matches a legend swatch's own data-legend-key 1:1 (Option id, or the Program id
                // in colorByProgram mode) - assets/controllers/lesson_timetable_controller.js's
                // click-to-filter toggling keys off this, not the rendered color itself.
                'legendKey' => $colorByProgram ? (string) $session->getProgram()->getId() : $this->optionLegendKey($session),
                // Redundant with the per-Program calendar's own page context (unused there, see
                // lesson_timetable_controller.js's default eventDetailFields), but the only way to
                // tell sessions from different Programs/Topics apart on
                // App\Controller\TeacherTimetableController's cross-Program personal feed.
                'program' => $session->getProgram()->getDisplayShortName(),
                'topic' => $session->getTopic()?->getName(),
                // Always included, even on the editable (staff) feed - unused there today (the
                // whole event already links to the edit-session-details form via 'url' below), but
                // harmless, and keeps this method the single source of truth for the route instead
                // of duplicating app_program_timetable_session_log generation elsewhere. Consumed
                // by the read-only feed's eventClick handler (assets/controllers/lesson_timetable_controller.js)
                // for the cahier de texte entry point, since visibility to view/edit it is
                // decided per-session by LessonLogVoter, not by editable/read-only feed mode.
                'logUrl' => $this->urlGenerator->generate('app_program_timetable_session_log', [
                    'id' => $session->getProgram()->getId(),
                    'sessionId' => $session->getId(),
                ]),
            ],
        ];

        if ($editable) {
            $event['url'] = $this->urlGenerator->generate('app_program_timetable_settings_sessions_edit', [
                'id' => $session->getProgram()->getId(),
                'sessionId' => $session->getId(),
            ]);
        }

        return $event;
    }

    private function optionsLabel(LessonSession $session): ?string
    {
        $names = array_map(static fn (Option $option): string => $option->getShortName(), $session->getOptions()->toArray());

        return [] === $names ? null : implode(', ', $names);
    }

    // A single Option makes the session's audience unambiguous, so it drives the event color;
    // zero or several Options fall back to the default color (see templates/program/_timetable_legend.html.twig).
    private function optionColor(LessonSession $session): string
    {
        $options = $session->getOptions()->toArray();

        return 1 === count($options) ? $options[0]->getColor() : self::DEFAULT_COLOR;
    }

    // Same one-Option-or-default rule as optionColor() above, expressed as the stable key a
    // legend swatch's data-legend-key can match against instead of comparing rendered colors.
    private function optionLegendKey(LessonSession $session): string
    {
        $options = $session->getOptions()->toArray();

        return 1 === count($options) ? (string) $options[0]->getId() : 'default';
    }
}
