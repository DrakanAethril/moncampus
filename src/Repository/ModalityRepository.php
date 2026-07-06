<?php

namespace App\Repository;

use App\Entity\Modality;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Modality>
 */
class ModalityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Modality::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('m')->select('COUNT(m.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Modality> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.ldapGroup', 'g')->addSelect('g')
            ->leftJoin('m.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('m.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('m.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('m.id', 'DESC')
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

        $qb->andWhere('m.name LIKE :search OR m.slug LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // Populates the "programs" collection on an already-fetched page of Modalities in a single
    // extra query, instead of one lazy-load query per row - the LEFT JOIN (rather than an
    // inner join) is required so Doctrine also marks the collection as initialized (empty)
    // for Modalities that have no linked program at all.
    /** @param list<Modality> $modalities */
    public function hydratePrograms(array $modalities): void
    {
        if ([] === $modalities) {
            return;
        }

        $this->createQueryBuilder('m')
            ->select('m', 'p')
            ->leftJoin('m.programs', 'p')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Modality $modality): ?int => $modality->getId(), $modalities))
            ->getQuery()
            ->getResult();
    }
}
