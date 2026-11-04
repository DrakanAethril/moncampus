<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\QuizTournamentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Where a tournament stands in its life (design/validated/quiz-tournament.md §3): born closed, then
 * opened before, during or after its period.
 */
class QuizTournamentStatusTest extends TestCase
{
    public function testATournamentNotOpenedIsClosedWhateverTheDate(): void
    {
        $start = new \DateTimeImmutable('2026-11-10 08:00');
        $end = new \DateTimeImmutable('2027-01-31 18:00');

        self::assertSame(QuizTournamentStatus::Closed, QuizTournamentStatus::of(false, $start, $end, new \DateTimeImmutable('2026-12-01')));
    }

    public function testAnOpenedTournamentFollowsItsPeriod(): void
    {
        $start = new \DateTimeImmutable('2026-11-10 08:00');
        $end = new \DateTimeImmutable('2027-01-31 18:00');

        self::assertSame(QuizTournamentStatus::Upcoming, QuizTournamentStatus::of(true, $start, $end, new \DateTimeImmutable('2026-11-10 07:59')));
        self::assertSame(QuizTournamentStatus::InProgress, QuizTournamentStatus::of(true, $start, $end, $start));
        self::assertSame(QuizTournamentStatus::Ended, QuizTournamentStatus::of(true, $start, $end, $end));
    }
}
