<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * A file the class import cannot even read as a list of students - a missing column, an empty
 * file, more rows than a class can hold. Not to be confused with the per-line findings the
 * analysis reports, which are findings on a file that WAS read.
 *
 * Carries a translation key rather than a message, same convention as
 * App\Service\AlternanceImport\ImportFileException.
 */
final class ClassImportFileException extends \RuntimeException
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
