<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\EvaluationPeriod;
use App\Entity\EvaluationPeriodGroup;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\Program;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Confirmer ces dates » (design/validated/school-year-import.md §6): the person read the dates the
 * school-year import moved on one screen and keeps them - the « À relire » marks of that screen go.
 * One method per screen that shows shifted dates; each flushes.
 */
final readonly class ShiftedDates
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function confirmPeriodGroup(PeriodGroup $group): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(Period::class, 'p')
            ->set('p.shiftedAt', ':none')
            ->where('p.periodGroup = :group')
            ->setParameter('none', null)
            ->setParameter('group', $group)
            ->getQuery()
            ->execute();
    }

    public function confirmEvaluationGroup(EvaluationPeriodGroup $group): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(EvaluationPeriod::class, 'p')
            ->set('p.shiftedAt', ':none')
            ->where('p.evaluationPeriodGroup = :group')
            ->setParameter('none', null)
            ->setParameter('group', $group)
            ->getQuery()
            ->execute();
    }

    public function confirmInternshipPeriods(Program $program): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(InternshipEvaluationPeriod::class, 'p')
            ->set('p.shiftedAt', ':none')
            ->where('p.program = :program')
            ->setParameter('none', null)
            ->setParameter('program', $program)
            ->getQuery()
            ->execute();
    }

    public function confirmPortfolio(Program $program): void
    {
        $program->markPortfolioDatesShifted(null);
        $this->entityManager->flush();
    }

    /** How many periods of a calendar still carry the mark - one query, for a DataTables screen. */
    public function countInPeriodGroup(PeriodGroup $group): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Period::class, 'p')
            ->where('p.periodGroup = :group')
            ->andWhere('p.shiftedAt IS NOT NULL')
            ->andWhere('p.inactiveDate IS NULL')
            ->setParameter('group', $group)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
