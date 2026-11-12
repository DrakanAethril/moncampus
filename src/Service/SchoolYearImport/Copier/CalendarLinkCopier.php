<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\ProgramPeriodGroup;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;

/**
 * « Rattachement aux calendriers »: which period calendars the formation follows and in which
 * order, and its evaluation calendar - pointed at the copies, or at the calendars the target year
 * already had under the same name. Links absent are added; the evaluation calendar is set only on
 * a formation that has none.
 */
final readonly class CalendarLinkCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::CalendarLinks)) {
            return;
        }

        $target = $pair->target;
        $write = $run->writable($pair);

        $linked = [];
        foreach ($target?->getProgramPeriodGroups() ?? [] as $link) {
            $linked[(int) $link->getPeriodGroup()?->getId()] = true;
        }
        foreach ($pair->source->getProgramPeriodGroups() as $link) {
            $sourceGroupId = (int) $link->getPeriodGroup()?->getId();
            if (!\array_key_exists($sourceGroupId, $run->periodGroups)) {
                continue;
            }
            $group = $run->periodGroups[$sourceGroupId];
            if (null !== $group && null !== $group->getId() && isset($linked[$group->getId()])) {
                continue;
            }
            $run->report->add($pair, 'periodLinks');
            if (null !== $write && null !== $group) {
                $copy = new ProgramPeriodGroup($write, $group, $link->getPriority());
                $write->getProgramPeriodGroups()->add($copy);
                $run->persist($copy);
            }
        }

        $sourceEvaluation = $pair->source->getEvaluationPeriodGroup();
        if (null === $sourceEvaluation || null !== $target?->getEvaluationPeriodGroup()) {
            return;
        }
        if (!\array_key_exists((int) $sourceEvaluation->getId(), $run->evaluationGroups)) {
            return;
        }
        $run->report->add($pair, 'evaluationCalendar');
        $write?->setEvaluationPeriodGroup($run->evaluationGroups[(int) $sourceEvaluation->getId()]);
    }
}
