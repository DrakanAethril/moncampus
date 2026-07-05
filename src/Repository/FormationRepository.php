<?php

namespace App\Repository;

use App\Entity\Formation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Formation>
 */
class FormationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Formation::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('f')->select('COUNT(f.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Formation> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->leftJoin('f.cohort', 'c')->addSelect('c')
            ->leftJoin('f.schoolYear', 'y')->addSelect('y')
            ->orderBy('f.id', 'DESC')
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

        $qb->andWhere('f.name LIKE :search OR f.shortName LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }
}
