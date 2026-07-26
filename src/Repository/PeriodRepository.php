<?php

namespace App\Repository;

use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\Program;
use App\Entity\ProgramPeriodGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Period>
 */
class PeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Period::class);
    }

    public function countAllForPeriodGroup(PeriodGroup $periodGroup, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)')->where('p.periodGroup = :periodGroup')->setParameter('periodGroup', $periodGroup);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Period> */
    public function findPageForPeriodGroupOrderedByMostRecent(PeriodGroup $periodGroup, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.type', 't')->addSelect('t')
            ->leftJoin('p.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('p.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('p.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('p.periodGroup = :periodGroup')
            ->setParameter('periodGroup', $periodGroup)
            ->orderBy('p.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the alternance calendar (InternshipCalendarBuilder) - a Program's attached
    // PeriodGroups (if any, via ProgramPeriodGroup) each supply their own Periods; a Program with
    // no PeriodGroup attached yet has none (empty array, not an error). Periods from
    // higher-priority groups are placed first in the returned list, since
    // InternshipCalendarBuilder::findPeriodForDate() resolves same-day overlaps by "first match
    // wins" - this is what makes the highest-priority group's Period win on a shared date.
    /** @return list<Period> */
    public function findAllActiveForProgram(Program $program): array
    {
        $links = $program->getProgramPeriodGroups()->toArray();
        usort($links, static fn (ProgramPeriodGroup $a, ProgramPeriodGroup $b): int => $a->getPriority() <=> $b->getPriority());

        $periods = [];
        foreach ($links as $link) {
            $periods = array_merge($periods, $this->findAllActiveForPeriodGroup($link->getPeriodGroup()));
        }

        return $periods;
    }

    // Powers PeriodGroup duplication (SettingsStructureController::duplicatePeriodGroup()) -
    // only active periods are carried over into the copy.
    /** @return list<Period> */
    public function findAllActiveForPeriodGroup(PeriodGroup $periodGroup): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.periodGroup = :periodGroup')
            ->andWhere('p.inactiveDate IS NULL')
            ->setParameter('periodGroup', $periodGroup)
            ->orderBy('p.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('p.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('p.inactiveDate IS NULL');
        }
    }
}
