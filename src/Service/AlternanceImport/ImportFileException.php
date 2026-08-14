<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

/**
 * A file the contract import cannot even read as a grid of contracts - not to be confused with the
 * per-line problems the analysis reports, which are findings on a file that WAS read.
 *
 * Carries a translation key rather than a message, same convention as App\Service\XlsxReadException.
 */
final class ImportFileException extends \RuntimeException
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
