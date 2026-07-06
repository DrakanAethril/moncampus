<?php

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\Option;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Maps a LessonSession to the FullCalendar event JSON shape, shared between the editable
 * timetable tab (ProgramSettingsController) and the read-only timetable page
 * (ProgramTimetableController) so both feeds stay in sync.
 */
class LessonSessionEventFormatter
{
    private const DEFAULT_COLOR = '#667382';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @param bool $editable Whether to include an edit URL (staff-facing feed only) */
    public function format(LessonSession $session, bool $editable): array
    {
        $day = $session->getDay();
        $start = $day->setTime((int) $session->getStartHour()->format('H'), (int) $session->getStartHour()->format('i'));
        $end = $day->setTime((int) $session->getEndHour()->format('H'), (int) $session->getEndHour()->format('i'));

        $event = [
            'id' => $session->getId(),
            'title' => $session->getDisplayName(),
            'start' => $start->format('Y-m-d\TH:i:s'),
            'end' => $end->format('Y-m-d\TH:i:s'),
            'backgroundColor' => $session->getLessonType()?->getAgendaColor() ?? self::DEFAULT_COLOR,
            'extendedProps' => [
                'teacher' => null !== $session->getTeacher() ? ($session->getTeacher()->getDisplayName() ?? $session->getTeacher()->getUsername()) : null,
                'classRoom' => $session->getClassRoom()?->getName(),
                'lessonType' => $session->getLessonType()?->getName(),
                'options' => $this->optionsLabel($session),
            ],
        ];

        if ($editable) {
            $event['url'] = $this->urlGenerator->generate('app_program_settings_timetable_sessions_edit', [
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
}
