<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * A declaration the rules do not accept - a mandate declared twice in one period, a refusal with no
 * reason on it.
 *
 * The message is a translation key: both cases are read on the spot by the person who tried.
 */
final class EngagementRefused extends \RuntimeException
{
}
