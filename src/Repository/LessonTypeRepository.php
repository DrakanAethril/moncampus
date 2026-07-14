<?php

namespace App\Repository;

use App\Entity\LessonType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LessonType>
 */
class LessonTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonType::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // Ordered by name ASC (not most-recent-first, unlike its siblings across the other structure
    // repositories) - Types de séances are picked from a dropdown/legend often enough that a
    // stable alphabetical order is more useful here than creation order.
    /** @return list<LessonType> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('l.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('l.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('l.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    /** @return list<LessonType> */
    public function findAllActiveOrderedByName(): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.inactiveDate IS NULL')
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('l.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }
}
