<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * There is no platform SSH key, or it cannot be read.
 *
 * Its own exception because the remedy is specific and the screens say so: generate one. Everything
 * that reaches inside a machine - guest accounts, post-installation, a batch's provisioning pass -
 * is unavailable until then, and saying "unavailable" without saying why would leave an
 * administrator looking for a network problem.
 */
class PlatformKeyUnavailableException extends \RuntimeException
{
}
