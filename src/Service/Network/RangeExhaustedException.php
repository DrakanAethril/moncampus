<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * The range has nothing left to hand out.
 *
 * Distinct from App\Service\Network\AddressUnavailableException because the screens answer them
 * differently: a full range is a fact about the declaration (widen the window, or free the
 * orphaned addresses the scan found), while a taken address is a fact about one choice.
 */
class RangeExhaustedException extends \RuntimeException
{
}
