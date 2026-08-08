<?php

namespace App\Service;

/**
 * An .xlsx App\Service\XlsxSheetReader cannot open, or that lacks the worksheet asked for.
 *
 * Carries a translation key rather than a message, same convention as QuizCsvImportException -
 * which it deliberately does NOT extend: the reader knows about spreadsheets, not about quizzes.
 * App\Service\KahootXlsxImporter is what turns one into the other, so the import controller still
 * has a single failure path to catch.
 */
final class XlsxReadException extends \RuntimeException
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
