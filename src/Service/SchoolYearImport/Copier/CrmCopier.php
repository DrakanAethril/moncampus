<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\CrmSchoolYear;
use App\Enum\SchoolYearImportBlock;
use App\Service\Crm\CrmConfigurationCopier;
use App\Service\Crm\CrmSettings;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;

/**
 * « CRM des inscriptions », through the CRM's own CrmConfigurationCopier (every part) - and only on a
 * formation with no CRM settings yet, since that copier overwrites (§3). The target year is opened
 * to the CRM when it has no CRM row at all, never as the default year.
 *
 * Runs after the importer's first flush: the CRM's settings are memoised by formation id.
 */
final readonly class CrmCopier implements ProgramCopier
{
    public function __construct(
        private CrmSettings $settings,
        private CrmConfigurationCopier $copier,
    ) {
    }

    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Crm) || null === $this->settings->settingsOf($pair->source)) {
            return;
        }
        $target = $pair->target;
        if (null !== $target && null !== $target->getId() && null !== $this->settings->settingsOf($target)) {
            return;
        }

        $run->report->add($pair, 'crm');
        $write = $run->writable($pair);
        if (null === $write) {
            return;
        }
        $this->copier->copy([['from' => $pair->source, 'to' => $write]], CrmConfigurationCopier::parts());

        if (!$run->crmYearOpened && null === $this->settings->yearRow($run->target)) {
            $year = new CrmSchoolYear($run->target);
            $year->setOpened(true);
            $run->persist($year);
            $run->crmYearOpened = true;
        }
    }
}
