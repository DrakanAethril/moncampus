<?php

declare(strict_types=1);

namespace App\Service\Ign;

/**
 * The Géoplateforme did not answer, answered with an error, or answered something this client does
 * not recognise. Every caller degrades on it - an indicator left empty, a pass retried later -
 * and never lets it fail the screen or the race it was enriching.
 */
final class IgnUnavailableException extends \RuntimeException
{
}
