<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the import would do with one line of the file - decided by App\Service\AlternanceImport\
 * ImportAnalyzer, shown on the analysis screen, and re-decided from scratch before anything is
 * written.
 */
enum AlternanceImportAction: string
{
    /** A new alternance, plus whatever employer/tutor account it needs. */
    case Create = 'create';

    /** The student already holds this very alternance (same tutor) - left untouched, not an error. */
    case Skip = 'skip';

    /** At least one Blocking finding on this line, so the file as a whole cannot be imported. */
    case Blocked = 'blocked';

    public function labelKey(): string
    {
        return match ($this) {
            self::Create => 'ufaContractImportActionCreateLabel',
            self::Skip => 'ufaContractImportActionSkipLabel',
            self::Blocked => 'ufaContractImportActionBlockedLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Create => 'cm-badge--green',
            self::Skip => 'cm-badge--gray',
            self::Blocked => 'cm-badge--red',
        };
    }
}
