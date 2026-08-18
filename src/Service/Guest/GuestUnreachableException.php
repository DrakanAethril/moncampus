<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * The machine could not be reached, or refused the platform key.
 *
 * Distinct from a command that ran and failed, because they mean different things to the person
 * reading the screen: one is a network or a key problem and affects everything, the other is one
 * command's business.
 */
class GuestUnreachableException extends \RuntimeException
{
}
