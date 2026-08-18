<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * The particular address that was asked for cannot be had - it is outside the assignable window, or
 * somebody already holds it.
 *
 * Raised only by the "I want this one" path: the "give me one" path never fails this way, it moves
 * on to the next address, which is the whole reason that path exists.
 */
class AddressUnavailableException extends \RuntimeException
{
}
