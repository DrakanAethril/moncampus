<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\EvaluationPeriod;
use App\Entity\EvaluationPeriodGroup;
use App\Entity\Program;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\SchoolYearImportReport;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Calendriers d'évaluation »: the evaluation calendars the formations taken over use. An
 * evaluation calendar belongs to no year, so its copy is named after the target year (R8, or the
 * name followed by the year) and recognised by that name among the active ones; it is never shared
 * between two years (R7) - a source calendar is never its own copy.
 */
final readonly class EvaluationCalendarCopier
{
    public const string KIND = 'evaluation';

    /** @param list<Program> $programs the source formations taken over */
    public function copy(ImportRun $run, array $programs): void
    {
        $sources = [];
        foreach ($programs as $program) {
            $group = $program->getEvaluationPeriodGroup();
            if (null !== $group && null === $group->getInactiveDate()) {
                $sources[(int) $group->getId()] = $group;
            }
        }
        if ([] === $sources) {
            return;
        }

        /** @var list<EvaluationPeriodGroup> $active */
        $active = $run->entityManager->getRepository(EvaluationPeriodGroup::class)->findBy(['inactiveDate' => null], ['id' => 'ASC']);
        $existing = [];
        foreach ($active as $group) {
            $existing[SchoolYearImportRules::key($group->getName())] ??= $group;
        }

        foreach ($sources as $sourceId => $source) {
            $name = $run->names->rewriteOrSuffix($source->getName());
            $found = $existing[SchoolYearImportRules::key($name)] ?? null;
            if (null !== $found && $found !== $source) {
                $run->evaluationGroups[$sourceId] = $found;
                $run->report->calendar(self::KIND, $name, SchoolYearImportReport::EXISTING, 0);
                continue;
            }
            if (!$run->has(SchoolYearImportBlock::EvaluationCalendars)) {
                continue;
            }

            $periods = $source->getPeriods()->toArray();
            $run->report->calendar(self::KIND, $name, SchoolYearImportReport::CREATED, \count($periods));

            $group = null;
            if (!$run->dryRun) {
                $group = new EvaluationPeriodGroup($name);
                $run->persist($group);
            }
            $run->evaluationGroups[$sourceId] = $group;

            foreach ($periods as $period) {
                $start = $period->getStartDate();
                $end = $period->getEndDate();
                if (null === $start || null === $end) {
                    continue;
                }
                $range = $run->shift->moveRange($start, $end);
                $run->report->shiftedDate(null, $name.' · '.$period->getName(), $start, $end, $range->start, $range->end, CopierFlags::of($range));
                if (null === $group) {
                    continue;
                }

                $copy = new EvaluationPeriod();
                $copy->setName($period->getName());
                $copy->setStartDate($range->start);
                $copy->setEndDate($range->end);
                $copy->markShifted($run->now);
                $group->addPeriod($copy);
                $run->persist($copy);
            }
        }
    }
}
