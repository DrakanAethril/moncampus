<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/**
 * A seating plan document refused whole. $reasonKey is a translation key the screen shows as is;
 * the message says, in English, which element broke the rule - for the logs and the tests.
 */
final class InvalidSeatingPlanException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $reasonKey,
        string $message,
    ) {
        parent::__construct($message);
    }
}
