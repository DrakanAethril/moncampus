<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * An input the correction cannot read - an option that does not exist. Its message is a stable code
 * the game reads (`option-unknown`).
 */
final class ScenarioInputRefused extends \DomainException
{
}
