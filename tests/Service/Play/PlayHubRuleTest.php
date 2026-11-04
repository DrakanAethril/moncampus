<?php

declare(strict_types=1);

namespace App\Tests\Service\Play;

use App\Service\Play\PlayHubRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The « Jouer » entry of the student's bar and its page (design/validated/quiz-tournament.md §15):
 * a pure student's, shown only when there is something behind it - a tournament that reaches them
 * or a serious game open to them.
 */
final class PlayHubRuleTest extends TestCase
{
    /** @param list<string> $roles */
    #[DataProvider('roles')]
    public function testOnlyAPureStudentHasTheEntry(array $roles, bool $expected): void
    {
        self::assertSame($expected, PlayHubRule::isPureStudent($roles));
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function roles(): iterable
    {
        yield 'a student' => [['ROLE_USER', 'ROLE_STUDENT'], true];
        yield 'a student who teaches' => [['ROLE_USER', 'ROLE_STUDENT', 'ROLE_TEACHER'], false];
        yield 'a student of the staff' => [['ROLE_USER', 'ROLE_STUDENT', 'ROLE_STAFF'], false];
        yield 'a student of the staff-lead' => [['ROLE_USER', 'ROLE_STUDENT', 'ROLE_STAFF-LEAD'], false];
        yield 'a student administrator' => [['ROLE_USER', 'ROLE_STUDENT', 'ROLE_ADMIN'], false];
        yield 'a teacher' => [['ROLE_USER', 'ROLE_TEACHER'], false];
        yield 'a tutor' => [['ROLE_USER', 'ROLE_TUTOR'], false];
    }

    public function testTheEntryNeedsSomethingBehindIt(): void
    {
        self::assertFalse(PlayHubRule::isVisible(true, false, false), 'nothing to play: no entry');
        self::assertTrue(PlayHubRule::isVisible(true, true, false), 'a tournament alone');
        self::assertTrue(PlayHubRule::isVisible(true, false, true), 'a serious game alone');
        self::assertTrue(PlayHubRule::isVisible(true, true, true));
        self::assertFalse(PlayHubRule::isVisible(false, true, true), 'never for an adult, whatever is open');
    }

    public function testATournamentIsListedFromItsOpeningUntilAMonthAfterItsEnd(): void
    {
        $now = new \DateTimeImmutable('2026-11-04 10:00');
        $start = new \DateTimeImmutable('2026-11-10 08:00');
        $end = new \DateTimeImmutable('2027-01-31 18:00');

        self::assertFalse(PlayHubRule::listsTournament(false, $end, $now), 'a closed tournament is nobody\'s to play');
        self::assertTrue(PlayHubRule::listsTournament(true, $end, $now), 'opened, not started yet: « Commence le … »');
        self::assertTrue(PlayHubRule::listsTournament(true, $end, $start->modify('+1 day')), 'in progress');
        self::assertTrue(PlayHubRule::listsTournament(true, $end, $end->modify('+30 days')), 'over: the final standings, for a month');
        self::assertFalse(PlayHubRule::listsTournament(true, $end, $end->modify('+30 days +1 second')));
    }
}
