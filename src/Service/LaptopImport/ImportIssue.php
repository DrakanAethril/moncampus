<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

use App\Enum\LaptopImportSeverity;

/**
 * One finding about one line of the file: a translation key, its parameters, and how much it costs.
 *
 * A key rather than a sentence, so the verification screen stays translatable like the rest of the
 * app (see translations/messages.fr.yaml, laptopImport*). Same shape and same reason as
 * App\Service\ClassImport\ImportIssue.
 */
final readonly class ImportIssue
{
    /** @param array<string, string|int> $parameters */
    private function __construct(
        public LaptopImportSeverity $severity,
        public string $messageKey,
        public array $parameters = [],
    ) {
    }

    /** @param array<string, string|int> $parameters */
    public static function blocking(string $messageKey, array $parameters = []): self
    {
        return new self(LaptopImportSeverity::Blocking, $messageKey, $parameters);
    }

    /** @param array<string, string|int> $parameters */
    public static function warning(string $messageKey, array $parameters = []): self
    {
        return new self(LaptopImportSeverity::Warning, $messageKey, $parameters);
    }

    /** @param array<string, string|int> $parameters */
    public static function note(string $messageKey, array $parameters = []): self
    {
        return new self(LaptopImportSeverity::Note, $messageKey, $parameters);
    }

    public function isBlocking(): bool
    {
        return LaptopImportSeverity::Blocking === $this->severity;
    }
}
