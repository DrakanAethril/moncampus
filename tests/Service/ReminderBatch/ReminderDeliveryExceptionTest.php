<?php

declare(strict_types=1);

namespace App\Tests\Service\ReminderBatch;

use App\Service\ReminderBatch\ReminderDeliveryException;
use PHPUnit\Framework\TestCase;

/**
 * Which SES answers are worth another try at the next pass, and which fail the line at once.
 * Insisting on a refusal only repeats it; giving up on throttling loses a mail that would have gone.
 */
class ReminderDeliveryExceptionTest extends TestCase
{
    public function testThrottlingAndOutagesArePassing(): void
    {
        self::assertTrue(ReminderDeliveryException::isPassing(400, 'Throttling'));
        self::assertTrue(ReminderDeliveryException::isPassing(429, 'TooManyRequestsException'));
        self::assertTrue(ReminderDeliveryException::isPassing(503, null));
        self::assertTrue(ReminderDeliveryException::isPassing(500, 'InternalFailure'));
        // No answer at all: the network, not the mail.
        self::assertTrue(ReminderDeliveryException::isPassing(null, null));
    }

    public function testARefusedMailFailsAtOnce(): void
    {
        self::assertFalse(ReminderDeliveryException::isPassing(400, 'MessageRejected'));
        self::assertFalse(ReminderDeliveryException::isPassing(400, 'BadRequestException'));
        self::assertFalse(ReminderDeliveryException::isPassing(403, null));
    }
}
