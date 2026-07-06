<?php

namespace App\Repository;

use App\Entity\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Section>
 */
class SectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Section::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Section> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.ldapGroup', 'g')->addSelect('g')
            ->leftJoin('s.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('s.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('s.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('s.id', 'DESC')
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

        $qb->andWhere('s.name LIKE :search OR s.slug LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }
}
