<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

/**
 * A draw that cannot give a question - a division by zero, a value that is not a number - and is
 * drawn again, like a false constraint. Never leaves the interpreter.
 */
final class RejectedDraw extends \RuntimeException
{
}
