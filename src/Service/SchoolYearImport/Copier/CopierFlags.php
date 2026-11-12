<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Service\SchoolYearImport\SchoolYearImportReport;
use App\Service\SchoolYearImport\ShiftedDate;
use App\Service\SchoolYearImport\ShiftedRange;

/** The flag a shifted date carries in the report: brought back to a bound, or fallen out of the year. */
final class CopierFlags
{
    public static function of(ShiftedRange|ShiftedDate $moved): ?string
    {
        if ($moved instanceof ShiftedRange && $moved->outside) {
            return SchoolYearImportReport::FLAG_OUTSIDE;
        }

        return $moved->clamped ? SchoolYearImportReport::FLAG_CLAMPED : null;
    }
}
