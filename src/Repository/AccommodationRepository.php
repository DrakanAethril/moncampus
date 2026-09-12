<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Accommodation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Accommodation>
 */
class AccommodationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Accommodation::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // Alphabetical rather than most-recent-first, like LessonTypeRepository and for the same
    // reason: the list is short, read as a catalogue, and picked from a checkbox group on the
    // annuaire fiche - the order the rows were created in tells nobody anything.
    /** @return list<Accommodation> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('a.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('a.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('a.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    /** @return list<Accommodation> */
    public function findAllActiveOrderedByName(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.inactiveDate IS NULL')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('a.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('a.inactiveDate IS NULL');
        }
    }
}
