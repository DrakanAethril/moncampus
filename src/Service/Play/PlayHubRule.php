<?php

declare(strict_types=1);

namespace App\Service\Play;

/**
 * Who gets the « Jouer » entry of the student's bar, and which tournaments its page lists
 * (design/validated/quiz-tournament.md §15), as pure functions on primitives. The facts are
 * PlayHub's.
 *
 * - The entry is a **pure student's** - the bar it sits in is the one `templates/layout/app.html.twig`
 *   draws for `is_pure_student`; adults reach the tournaments from Outils and the games from
 *   Ressources.
 * - It is shown only when something is behind it: a tournament that reaches the student, or a
 *   serious game open to them. It never carries a counter (§7: no alert outside the tournament).
 * - A tournament is listed once opened - before its start (« Commence le … ») and while it runs -
 *   and for a month after its end, so that the final standings can still be read from the page.
 */
final class PlayHubRule
{
    /** How long after its end a tournament stays on the page, its standings frozen. */
    public const int ENDED_DAYS = 30;

    private const array ADULT_ROLES = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TEACHER'];

    /** @param list<string> $roles */
    public static function isPureStudent(array $roles): bool
    {
        return \in_array('ROLE_STUDENT', $roles, true) && [] === array_intersect(self::ADULT_ROLES, $roles);
    }

    public static function isVisible(bool $pureStudent, bool $hasTournament, bool $hasGame): bool
    {
        return $pureStudent && ($hasTournament || $hasGame);
    }

    public static function listsTournament(bool $opened, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): bool
    {
        return $opened && $now <= $endsAt->modify(\sprintf('+%d days', self::ENDED_DAYS));
    }
}
