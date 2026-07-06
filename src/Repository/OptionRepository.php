<?php

namespace App\Repository;

use App\Entity\Option;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Option>
 */
class OptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Option::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('o')->select('COUNT(o.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Option> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.ldapGroup', 'g')->addSelect('g')
            ->leftJoin('o.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('o.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('o.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('o.id', 'DESC')
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

        $qb->andWhere('o.name LIKE :search OR o.slug LIKE :search OR o.shortName LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // Populates the "programs" collection on an already-fetched page of Options in a single
    // extra query, instead of one lazy-load query per row - the LEFT JOIN (rather than an
    // inner join) is required so Doctrine also marks the collection as initialized (empty)
    // for Options that have no linked program at all.
    /** @param list<Option> $options */
    public function hydratePrograms(array $options): void
    {
        if ([] === $options) {
            return;
        }

        $this->createQueryBuilder('o')
            ->select('o', 'p')
            ->leftJoin('o.programs', 'p')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Option $option): ?int => $option->getId(), $options))
            ->getQuery()
            ->getResult();
    }
}
