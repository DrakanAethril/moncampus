<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Enum\ClassImportSeverity;

/**
 * One finding about one line of the file: a translation key, its parameters, and how much it costs.
 *
 * A key rather than a sentence, so the verification screen stays translatable like the rest of the
 * app (see translations/messages.fr.yaml, classImport*).
 *
 * $keyParameters holds the parameters whose value is itself a translation key - an account type, so
 * far - which the template translates before substituting. Kept apart from $parameters rather than
 * guessed at rendering time, because a student's actual name could perfectly well look like a key.
 */
final readonly class ImportIssue
{
    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $keyParameters
     */
    private function __construct(
        public ClassImportSeverity $severity,
        public string $messageKey,
        public array $parameters = [],
        public array $keyParameters = [],
    ) {
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $keyParameters
     */
    public static function blocking(string $messageKey, array $parameters = [], array $keyParameters = []): self
    {
        return new self(ClassImportSeverity::Blocking, $messageKey, $parameters, $keyParameters);
    }

    /** @param array<string, string> $parameters */
    public static function warning(string $messageKey, array $parameters = []): self
    {
        return new self(ClassImportSeverity::Warning, $messageKey, $parameters);
    }

    /** @param array<string, string> $parameters */
    public static function note(string $messageKey, array $parameters = []): self
    {
        return new self(ClassImportSeverity::Note, $messageKey, $parameters);
    }

    public function isBlocking(): bool
    {
        return ClassImportSeverity::Blocking === $this->severity;
    }

    /** @param array<string, string> $parameters */
    public function withParameters(array $parameters): self
    {
        return new self($this->severity, $this->messageKey, [...$this->parameters, ...$parameters], $this->keyParameters);
    }
}
