<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\SchoolYearImportReport;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Calendriers de périodes »: the source year's active period calendars, each recognised in the
 * target year by its name after R8 - left as it is, no period added - or created with its active
 * periods (type, modalities), their dates shifted (R4) and marked « À relire ».
 *
 * A calendar recognised is mapped even when the box is unticked, so that « Rattachement aux
 * calendriers » can point at it.
 */
final readonly class PeriodCalendarCopier
{
    public const string KIND = 'period';

    public function copy(ImportRun $run): void
    {
        /** @var list<PeriodGroup> $sources */
        $sources = $run->entityManager->createQueryBuilder()
            ->select('g', 'p', 'm')
            ->from(PeriodGroup::class, 'g')
            ->leftJoin('g.periods', 'p')
            ->leftJoin('p.modalities', 'm')
            ->where('g.schoolYear = :year')
            ->andWhere('g.inactiveDate IS NULL')
            ->setParameter('year', $run->source)
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<PeriodGroup> $targets */
        $targets = $run->entityManager->getRepository(PeriodGroup::class)->findBy(['schoolYear' => $run->target, 'inactiveDate' => null], ['id' => 'ASC']);
        $existing = [];
        foreach ($targets as $group) {
            $existing[SchoolYearImportRules::key($group->getName())] ??= $group;
        }

        foreach ($sources as $source) {
            $name = $run->names->rewrite($source->getName());
            $found = $existing[SchoolYearImportRules::key($name)] ?? null;
            if (null !== $found) {
                $run->periodGroups[(int) $source->getId()] = $found;
                $run->report->calendar(self::KIND, $name, SchoolYearImportReport::EXISTING, 0);
                continue;
            }
            if (!$run->has(SchoolYearImportBlock::PeriodCalendars)) {
                continue;
            }

            $periods = array_values(array_filter($source->getPeriods()->toArray(), static fn (Period $period): bool => null === $period->getInactiveDate()));
            usort($periods, static fn (Period $a, Period $b): int => [$a->getStartDate(), $a->getId()] <=> [$b->getStartDate(), $b->getId()]);
            $run->report->calendar(self::KIND, $name, SchoolYearImportReport::CREATED, \count($periods));

            $group = null;
            if (!$run->dryRun) {
                $group = new PeriodGroup($name, $run->target);
                $run->persist($group);
            }
            $run->periodGroups[(int) $source->getId()] = $group;
            $existing[SchoolYearImportRules::key($name)] = $group ?? $source;

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

                $copy = new Period($period->getName(), $range->start, $range->end, $period->getType(), $group);
                $copy->markShifted($run->now);
                foreach ($period->getModalities() as $modality) {
                    if (null === $modality->getInactiveDate()) {
                        $modality->addPeriod($copy);
                    }
                }
                $run->persist($copy);
            }
        }
    }
}
