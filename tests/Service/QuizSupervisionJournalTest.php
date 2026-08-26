<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizSupervisionJournal;
use PHPUnit\Framework\TestCase;

/**
 * How much a client is believed about an absence it declares.
 *
 * The rule of the journal is that durations come from two instants the server wrote. This is the
 * single exception - the departure beacon was lost, so there is nothing to subtract from - and it
 * is bounded so that a lying client can only ever shorten an absence, never invent one out of
 * nothing and never claim to have been away longer than the application knows it can have been.
 */
class QuizSupervisionJournalTest extends TestCase
{
    public function testNothingDeclaredMeansNothingRecorded(): void
    {
        self::assertSame(0, QuizSupervisionJournal::boundedDurationMs(null, $this->at('10:00:00'), $this->at('10:00:40')));
        self::assertSame(0, QuizSupervisionJournal::boundedDurationMs(0, $this->at('10:00:00'), $this->at('10:00:40')));
        self::assertSame(0, QuizSupervisionJournal::boundedDurationMs(-4000, $this->at('10:00:00'), $this->at('10:00:40')));
    }

    public function testAPlausibleClaimIsTakenAsItStands(): void
    {
        // Away 12 s, last seen 40 s ago: the claim fits inside what the application knows.
        self::assertSame(12000, QuizSupervisionJournal::boundedDurationMs(12000, $this->at('10:00:00'), $this->at('10:00:40')));
    }

    /** A client claiming more than the whole interval it was gone for is cut down to that interval. */
    public function testAClaimLongerThanTheKnownIntervalIsClamped(): void
    {
        self::assertSame(40000, QuizSupervisionJournal::boundedDurationMs(600000, $this->at('10:00:00'), $this->at('10:00:40')));
    }

    /** Clocks disagree: a "last known" in the future must not produce a negative absence. */
    public function testAKnownInstantInTheFutureYieldsNothing(): void
    {
        self::assertSame(0, QuizSupervisionJournal::boundedDurationMs(12000, $this->at('10:01:00'), $this->at('10:00:40')));
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-26 '.$time);
    }
}
