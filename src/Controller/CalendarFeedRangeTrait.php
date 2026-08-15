<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

// Shared by the three calendar feeds (program, program settings, teacher), which all feed the same
// Stimulus controller (assets/controllers/lesson_timetable_controller.js).
//
// FullCalendar asks for its events again on every change of week and bounds its request by the range
// displayed. It is that range, and it alone, that must come back: without it a feed returns every
// slot of the program, across all periods, on every navigation.
//
// The range arrives in the request BODY and not in the URL, the event source being declared as POST
// on the JavaScript side - hence $request->request and not $request->query.
trait CalendarFeedRangeTrait
{
    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function calendarFeedRange(Request $request): array
    {
        return [
            new \DateTimeImmutable((string) $request->request->get('start', 'now')),
            new \DateTimeImmutable((string) $request->request->get('end', 'now')),
        ];
    }
}
