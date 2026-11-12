<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\PortfolioValidator;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Portfolio E5 / E6 »: the référentiel, the cursus year, the exam session moved by as many years
 * as the two years are apart, the deadlines shifted (R4) and marked « À relire » - only on a
 * formation that has no référentiel yet. « Validateurs du portfolio »: the validators of each
 * option who are members of the target team (R6), absent ones added.
 */
final readonly class PortfolioSettingsCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Portfolio)) {
            return;
        }

        $source = $pair->source;
        $write = $run->writable($pair);
        $referential = $source->getPortfolioReferential();
        if (null !== $referential && null === $pair->target?->getPortfolioReferential()) {
            $run->report->add($pair, 'portfolio');
            $shifted = false;
            $deadlines = [];
            foreach (['E5' => $source->getPortfolioE5Deadline(), 'E6' => $source->getPortfolioE6Deadline()] as $exam => $deadline) {
                if (null === $deadline) {
                    $deadlines[$exam] = null;
                    continue;
                }
                $moved = $run->shift->move($deadline);
                $deadlines[$exam] = $moved->date;
                $shifted = true;
                $run->report->shiftedDate($pair, $pair->name.' · Portfolio · échéance '.$exam, $deadline, null, $moved->date, null, CopierFlags::of($moved));
            }

            if (null !== $write) {
                $write->setPortfolioEnabled($source->isPortfolioEnabled());
                $write->setPortfolioReferential($referential);
                $write->setPortfolioCursusYear($source->getPortfolioCursusYear());
                $write->setPortfolioExamSession(SchoolYearImportRules::examSession(
                    $source->getPortfolioExamSession(),
                    (int) $run->source->getStartDate()?->format('Y'),
                    (int) $run->target->getStartDate()?->format('Y'),
                ));
                $write->setPortfolioE5Deadline($deadlines['E5']);
                $write->setPortfolioE6Deadline($deadlines['E6']);
                $write->markPortfolioDatesShifted($shifted ? $run->now : null);
            }
        }

        if (!$run->has(SchoolYearImportBlock::PortfolioValidators)) {
            return;
        }

        $held = [];
        foreach ($run->data->rowsOf(PortfolioValidator::class, $pair->target) as $validator) {
            if ($validator->isActive()) {
                $held[$validator->getOption()?->getId().'-'.$validator->getTeacher()?->getId()] = true;
            }
        }
        foreach ($run->data->rowsOf(PortfolioValidator::class, $source) as $validator) {
            $option = $validator->getOption();
            $teacher = $validator->getTeacher();
            $key = $option?->getId().'-'.$teacher?->getId();
            if (!$validator->isActive() || null === $option || null === $teacher || isset($held[$key]) || !$pair->inTeam($teacher) || !$pair->hasOption($option)) {
                continue;
            }
            $held[$key] = true;
            $run->report->add($pair, 'portfolioValidators');
            if (null !== $write && null !== $run->actor) {
                $run->persist(new PortfolioValidator($write, $option, $teacher, $run->actor));
            }
        }
    }
}
