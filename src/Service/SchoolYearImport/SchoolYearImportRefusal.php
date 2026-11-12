<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

/**
 * Why an import cannot be planned or written - a translation key and its parameters. Thrown
 * inside the import's transaction, it rolls everything back (R3).
 */
final class SchoolYearImportRefusal extends \RuntimeException
{
    /** @param array<string, string|int> $params */
    private function __construct(public readonly string $key, public readonly array $params)
    {
        parent::__construct($key);
    }

    /** @param array<string, string|int> $params */
    public static function because(string $key, array $params = []): self
    {
        return new self($key, $params);
    }
}
