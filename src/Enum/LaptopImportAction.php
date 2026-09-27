<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the import would do with one line of the file - decided by
 * App\Service\LaptopImport\LaptopImportAnalyzer, shown on the verification screen, and decided again
 * from scratch just before anything is written.
 */
enum LaptopImportAction: string implements HasBadge
{
    /** No machine carries this inventory number nor this serial number: a new row in the fleet. */
    case Create = 'create';

    /**
     * This very machine is already in the inventory - same inventory number, same serial number.
     *
     * Left alone rather than updated: the file is a purchase list, and what the inventory knows
     * about a machine it already holds (its notes, its état, its loans) was not written from a
     * spreadsheet. Re-uploading the same file is therefore idempotent and says so.
     */
    case Skip = 'skip';

    /** At least one Blocking finding on this line, so the file as a whole cannot be imported. */
    case Blocked = 'blocked';

    public function labelKey(): string
    {
        return match ($this) {
            self::Create => 'laptopImportActionCreateLabel',
            self::Skip => 'laptopImportActionSkipLabel',
            self::Blocked => 'laptopImportActionBlockedLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Create => BadgeTone::Green,
            self::Skip => BadgeTone::Gray,
            self::Blocked => BadgeTone::Red,
        };
    }
}
