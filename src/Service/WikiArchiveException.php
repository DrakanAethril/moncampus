<?php

declare(strict_types=1);

namespace App\Service;

/**
 * An archive that may not be opened or read, carrying the translation key the screen shows.
 *
 * A message key rather than a sentence, because every one of these is user-facing: an import that
 * fails silently, or that fails with an English exception message, is an import somebody re-tries
 * five times before asking.
 */
class WikiArchiveException extends \RuntimeException
{
    public function __construct(public readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }
}
