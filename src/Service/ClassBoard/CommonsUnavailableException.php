<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

/**
 * Wikimedia Commons did not answer, or not with what was expected. Never an error of the platform:
 * the boards keep the last photograph, and the next pass tries again.
 */
final class CommonsUnavailableException extends \RuntimeException
{
}
