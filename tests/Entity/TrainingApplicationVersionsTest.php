<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TrainingApplication;
use App\Entity\TrainingApplicationVersion;
use PHPUnit\Framework\TestCase;

/**
 * Which version a validator is judging, and which ones they may look back at (screen 8d).
 *
 * The association carries no ORM order, so both answers rest on the numbers alone. That is worth
 * pinning: a resend that reads the wrong version would show the validator the text they already
 * commented on, and nothing on the screen would say so.
 */
class TrainingApplicationVersionsTest extends TestCase
{
    private function applicationWith(int ...$numbers): TrainingApplication
    {
        $application = new TrainingApplication();

        foreach ($numbers as $number) {
            $application->addVersion((new TrainingApplicationVersion())->setNumber($number)->setBody('v'.$number));
        }

        return $application;
    }

    public function testTheCurrentVersionIsTheHighestNumberedOne(): void
    {
        // Added out of order on purpose: nothing sorts the collection on the way in.
        $application = $this->applicationWith(2, 1, 3);

        $current = $application->getCurrentVersion();

        self::assertNotNull($current);
        self::assertSame(3, $current->getNumber());
        self::assertSame(3, $application->getVersionNumber());
        self::assertSame('v3', $current->getBody());
    }

    public function testEarlierVersionsAreListedMostRecentFirstAndExcludeTheCurrentOne(): void
    {
        $application = $this->applicationWith(2, 1, 3);

        self::assertSame([2, 1], array_map(
            static fn (TrainingApplicationVersion $version): int => $version->getNumber(),
            $application->previousVersions(),
        ));
    }

    public function testAFirstSendHasNoHistoryToShow(): void
    {
        self::assertSame([], $this->applicationWith(1)->previousVersions());
    }
}
