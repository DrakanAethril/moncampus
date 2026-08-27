<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * A gesture the rules do not allow - an empty envelope, a value that is not offered, a malus with
 * no object or on a formation that refused the malus altogether.
 *
 * The message is a translation key: every refusal here is read by a teacher on the spot, and the
 * whole point of the envelope and of the bounds is that they are *said*, not silently applied.
 */
final class GestureRefused extends \RuntimeException
{
}
