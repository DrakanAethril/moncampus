<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A validator's verdict on one element of a training application.
 *
 * `Pending` is a real state, not a null: an element nobody has looked at yet is different from one
 * that was refused, and screen 8d shows them differently.
 */
enum TrainingApplicationDecision: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Refused = 'refused';
}
