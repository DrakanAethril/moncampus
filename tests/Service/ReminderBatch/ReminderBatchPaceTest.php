<?php

declare(strict_types=1);

namespace App\Tests\Service\ReminderBatch;

use App\Service\ReminderBatch\ReminderBatchPace;
use PHPUnit\Framework\TestCase;

/**
 * The platform's pace (R5): so many mails a minute, on working days, within the sending hours.
 * 2026-11-09 is a Monday, 2026-11-13 a Friday.
 */
class ReminderBatchPaceTest extends TestCase
{
    public function testTheWindowIsTheWorkingDaysWithinTheHours(): void
    {
        $pace = new ReminderBatchPace(10, 8, 18);

        self::assertTrue($pace->isOpen($this->at('2026-11-09 10:00')));
        self::assertTrue($pace->isOpen($this->at('2026-11-09 08:00')));
        self::assertTrue($pace->isOpen($this->at('2026-11-13 17:59')));
        self::assertFalse($pace->isOpen($this->at('2026-11-09 07:59')));
        self::assertFalse($pace->isOpen($this->at('2026-11-09 18:00')));
        self::assertFalse($pace->isOpen($this->at('2026-11-14 10:00')));
        self::assertFalse($pace->isOpen($this->at('2026-11-15 10:00')));
    }

    public function testTheNextOpening(): void
    {
        $pace = new ReminderBatchPace(10, 8, 18);

        self::assertEquals($this->at('2026-11-09 10:00'), $pace->nextOpening($this->at('2026-11-09 10:00')));
        self::assertEquals($this->at('2026-11-09 08:00'), $pace->nextOpening($this->at('2026-11-09 06:30')));
        self::assertEquals($this->at('2026-11-10 08:00'), $pace->nextOpening($this->at('2026-11-09 18:00')));
        self::assertEquals($this->at('2026-11-16 08:00'), $pace->nextOpening($this->at('2026-11-13 18:30')));
        self::assertEquals($this->at('2026-11-16 08:00'), $pace->nextOpening($this->at('2026-11-14 12:00')));
    }

    public function testTheEndIsThePassThatSendsTheLastLine(): void
    {
        $pace = new ReminderBatchPace(10, 8, 18);

        // Passes at 10:01 (10), 10:02 (20), 10:03 (25).
        self::assertEquals($this->at('2026-11-09 10:03'), $pace->estimateEnd($this->at('2026-11-09 10:00:20'), 25));
        self::assertEquals($this->at('2026-11-09 10:01'), $pace->estimateEnd($this->at('2026-11-09 10:00:20'), 10));
    }

    public function testAnEndPastTheClosingWaitsForTheNextWorkingMorning(): void
    {
        $pace = new ReminderBatchPace(10, 8, 18);

        // 18:00 is shut: Monday 08:00 (10), 08:01 (15).
        self::assertEquals($this->at('2026-11-16 08:01'), $pace->estimateEnd($this->at('2026-11-13 17:59:30'), 15));
    }

    public function testNothingLeftHasNoEnd(): void
    {
        self::assertNull((new ReminderBatchPace(10, 8, 18))->estimateEnd($this->at('2026-11-09 10:00'), 0));
    }

    public function testTheSettingsAreReadFromTheEnvironmentForm(): void
    {
        $pace = ReminderBatchPace::fromSettings(30, '8-18');

        self::assertSame(30, $pace->perMinute);
        self::assertSame(8, $pace->openHour);
        self::assertSame(18, $pace->closeHour);
    }

    public function testMeaninglessSettingsAreRefusedRatherThanGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReminderBatchPace::fromSettings(30, '18-8');
    }

    public function testAZeroPaceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReminderBatchPace::fromSettings(0, '8-18');
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('Europe/Paris'));
    }
}
