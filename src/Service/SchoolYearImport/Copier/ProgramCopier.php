<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;

/**
 * One block of the school-year import's catalogue for one formation (design/validated/
 * school-year-import.md §3). A copier reads the source formation, recognises what the target
 * already has by the block's key, and creates only what is missing (R5) - in a dry run it only
 * counts. It never modifies nor removes a row, never flushes, and says what it added through the
 * run's report.
 */
interface ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void;
}
