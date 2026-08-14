<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

use App\Enum\AlternanceImportSeverity;

/**
 * One finding about one line of the file: a translation key, its parameters, and how much it costs.
 *
 * A key rather than a sentence, so the analysis screen stays translatable like the rest of the app
 * (see translations/messages.fr.yaml, ufaContractImportIssue*).
 */
final readonly class ImportIssue
{
    /** @param array<string, string> $parameters */
    private function __construct(
        public AlternanceImportSeverity $severity,
        public string $messageKey,
        public array $parameters = [],
    ) {
    }

    /** @param array<string, string> $parameters */
    public static function blocking(string $messageKey, array $parameters = []): self
    {
        return new self(AlternanceImportSeverity::Blocking, $messageKey, $parameters);
    }

    /** @param array<string, string> $parameters */
    public static function warning(string $messageKey, array $parameters = []): self
    {
        return new self(AlternanceImportSeverity::Warning, $messageKey, $parameters);
    }

    /** @param array<string, string> $parameters */
    public static function note(string $messageKey, array $parameters = []): self
    {
        return new self(AlternanceImportSeverity::Note, $messageKey, $parameters);
    }

    public function isBlocking(): bool
    {
        return AlternanceImportSeverity::Blocking === $this->severity;
    }

    public function isWarning(): bool
    {
        return AlternanceImportSeverity::Warning === $this->severity;
    }
}
