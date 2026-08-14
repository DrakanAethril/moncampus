<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much one finding of the UFA contract analysis costs.
 *
 * Blocking is the strong word: a single Blocking finding anywhere in the file disables the
 * confirmation button entirely, rather than dropping its own line. An import that half-writes a
 * promotion's contracts is worse than one that refuses - the file gets fixed and re-uploaded.
 */
enum AlternanceImportSeverity: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';

    /** Neither wrong nor risky - what the import will do with a line that needs explaining. */
    case Note = 'note';

    public function labelKey(): string
    {
        return match ($this) {
            self::Blocking => 'ufaContractImportSeverityBlockingLabel',
            self::Warning => 'ufaContractImportSeverityWarningLabel',
            self::Note => 'ufaContractImportSeverityNoteLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Blocking => 'cm-badge--red',
            self::Warning => 'cm-badge--gold',
            self::Note => 'cm-badge--gray',
        };
    }
}
