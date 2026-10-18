<?php

declare(strict_types=1);

namespace App\SeriousGame;

/**
 * A call of the game the platform refuses - a hidden unit, a final test still locked, a session
 * that is not the player's. Its message is a stable code the game reads (`final-locked`…), turned
 * into a 409 by the SDK's controller.
 */
final class SeriousGamePlayRefused extends \DomainException
{
}
