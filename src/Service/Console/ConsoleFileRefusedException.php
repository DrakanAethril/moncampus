<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * The machine would not hand the file over, or would not take it.
 *
 * A missing path, a permission the console's account does not have, a full disk. The message is a
 * translation key: somebody who asked for a file has no use for `base64`'s own words.
 */
class ConsoleFileRefusedException extends \RuntimeException
{
}
