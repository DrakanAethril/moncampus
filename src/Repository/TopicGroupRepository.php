<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\TopicGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TopicGroup>
 */
class TopicGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TopicGroup::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('tg')->select('COUNT(tg.id)')->where('tg.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<TopicGroup> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('tg')
            ->leftJoin('tg.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('tg.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('tg.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('tg.program = :program')
            ->setParameter('program', $program)
            ->orderBy('tg.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the TopicGroup dropdown on the Topic form - only that program's own active groups
    // are valid choices.
    /** @return list<TopicGroup> */
    public function findAllActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('tg')
            ->where('tg.program = :program')
            ->andWhere('tg.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('tg.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('tg.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('tg.inactiveDate IS NULL');
        }
    }
}
