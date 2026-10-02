<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

/**
 * A layout document refused whole, at its first invalid element. The message is for the logs and
 * the tests; the page only says « Non enregistré » and keeps what it shows.
 */
final class InvalidClassBoardLayoutException extends \RuntimeException
{
}
