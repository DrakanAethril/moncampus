<?php

namespace App\Repository;

use App\Entity\InternshipSkillLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipSkillLevel>
 */
class InternshipSkillLevelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipSkillLevel::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipSkillLevel> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('l.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('l.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('l.orderIndex', 'ASC')
            ->addOrderBy('l.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the booklet's competency grid column headers.
    /** @return list<InternshipSkillLevel> */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.inactiveDate IS NULL')
            ->orderBy('l.orderIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('l.label LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }
}
