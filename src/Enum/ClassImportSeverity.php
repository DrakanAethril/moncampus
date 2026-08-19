<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much one finding of the class-import analysis costs.
 *
 * Blocking is the strong word: a single Blocking finding anywhere in the file disables the
 * confirmation button entirely, rather than dropping its own line. Same rule and same reason as
 * the UFA contract import - an import that half-writes a class is the state nobody knows how to
 * get out of; the file gets fixed and uploaded again.
 */
enum ClassImportSeverity: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';

    /** Neither wrong nor risky - what the import will do with a line that needs explaining. */
    case Note = 'note';

    public function labelKey(): string
    {
        return match ($this) {
            self::Blocking => 'classImportSeverityBlockingLabel',
            self::Warning => 'classImportSeverityWarningLabel',
            self::Note => 'classImportSeverityNoteLabel',
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
