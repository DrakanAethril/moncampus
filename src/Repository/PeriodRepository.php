<?php

namespace App\Repository;

use App\Entity\Period;
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

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Period> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('p.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }
}
