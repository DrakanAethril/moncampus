<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

// Shared by the three calendar feeds (formation, paramétrage de la formation, enseignant), qui
// alimentent tous le même contrôleur Stimulus (assets/controllers/lesson_timetable_controller.js).
//
// FullCalendar redemande ses évènements à chaque changement de semaine et borne sa requête par la
// plage affichée. C'est cette plage, et elle seule, qui doit revenir : sans elle un feed renvoie
// l'intégralité des créneaux de la formation, toutes périodes confondues, à chaque navigation.
//
// La plage arrive dans le CORPS de la requête et non dans l'URL, la source d'évènements étant
// déclarée en POST côté JavaScript - d'où $request->request et non $request->query.
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
