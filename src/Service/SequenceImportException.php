<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A pasted document App\Service\SequenceJsonImporter cannot use at all - not JSON, not this format,
 * not a single séance, or no `rapport` block. As opposed to a *field* it could not read, which the
 * payload reports in its `warnings` list and which never stops the import.
 *
 * Carries a translation key rather than a message so the controller decides the locale, exactly like
 * App\Service\QuizCsvImportException. A separate class rather than that one because the two describe
 * different documents: sharing it would make a quiz error message reachable from a séquence screen.
 */
final class SequenceImportException extends \RuntimeException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(private readonly string $messageKey, private readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /** @return array<string, string|int> */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
