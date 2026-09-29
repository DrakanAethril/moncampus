<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JsonRequestPayload;
use PHPUnit\Framework\TestCase;

/**
 * An instant sent by a client lands in the server's zone - the one Doctrine reads a DATETIME back
 * in. Kept in the zone it arrived in, a phone's « …Z » came back two hours early.
 */
class JsonRequestPayloadInstantTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testAUtcInstantIsMovedIntoTheServersZone(): void
    {
        $instant = JsonRequestPayload::fromArray(['at' => '2026-09-29T08:00:00.000Z'])->instant('at');

        self::assertNotNull($instant);
        self::assertSame('Europe/Paris', $instant->getTimezone()->getName());
        // What Doctrine writes: the wall time. It must be Paris's, not UTC's.
        self::assertSame('2026-09-29 10:00:00', $instant->format('Y-m-d H:i:s'));
        self::assertSame(new \DateTimeImmutable('2026-09-29T08:00:00Z')->getTimestamp(), $instant->getTimestamp());
    }

    public function testWinterTimeIsOneHour(): void
    {
        $instant = JsonRequestPayload::fromArray(['at' => '2026-12-01T08:00:00Z'])->instant('at');

        self::assertSame('2026-12-01 09:00:00', $instant?->format('Y-m-d H:i:s'));
    }

    public function testAnAbsentOrUnreadableInstantIsNull(): void
    {
        $payload = JsonRequestPayload::fromArray(['empty' => '', 'garbage' => 'hier soir', 'number' => 12]);

        self::assertNull($payload->instant('missing'));
        self::assertNull($payload->instant('empty'));
        self::assertNull($payload->instant('garbage'));
    }
}
