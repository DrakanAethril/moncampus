<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much one finding of the laptop-inventory import analysis costs.
 *
 * Blocking is the strong word: a single Blocking finding anywhere in the file disables the
 * confirmation button entirely, rather than dropping its own line. Same rule and same reason as the
 * class import and the UFA contract import - a file that half-imported leaves an inventory nobody
 * can reconcile against the spreadsheet it came from; the file gets fixed and uploaded again.
 */
enum LaptopImportSeverity: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';

    /** Neither wrong nor risky - what the import will do with a line that needs explaining. */
    case Note = 'note';

    public function labelKey(): string
    {
        return match ($this) {
            self::Blocking => 'laptopImportSeverityBlockingLabel',
            self::Warning => 'laptopImportSeverityWarningLabel',
            self::Note => 'laptopImportSeverityNoteLabel',
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
