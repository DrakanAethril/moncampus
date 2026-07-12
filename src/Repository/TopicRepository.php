<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\Topic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Topic>
 */
class TopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Topic::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)')->where('t.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Topic> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.teacher', 'te')->addSelect('te')
            ->leftJoin('t.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('t.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('t.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('t.program = :program')
            ->setParameter('program', $program)
            ->orderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the Topic dropdown on the lesson session form - only that program's own active
    // topics are valid choices.
    /** @return list<Topic> */
    public function findAllActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.program = :program')
            ->andWhere('t.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers App\Controller\ProgramSyllabusController and the Topics settings tab on
    // App\Controller\ProgramTimetableSettingsController - the whole table is rendered server-side
    // in one page (no pagination, a Program's own topic list is small), with DataTables/RowGroup
    // doing the actual grouping/sorting/hour-total calculation client-side, so this just needs a
    // sensible initial order (matching what the client-side sort will produce anyway) and
    // topicGroup/teacher/topicGroup.options eager-loaded to avoid an N+1 per row - the syllabus
    // page's per-Option hour totals need every topic's group's Option scoping.
    /** @return list<Topic> */
    public function findAllForProgramOrderedByTopicGroup(Program $program, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('g', 'te', 'go')
            ->innerJoin('t.topicGroup', 'g')
            ->leftJoin('g.options', 'go')
            ->leftJoin('t.teacher', 'te')
            ->where('t.program = :program')
            ->setParameter('program', $program)
            ->orderBy('g.name', 'ASC')
            ->addOrderBy('t.name', 'ASC');

        if (!$includeInactive) {
            $qb->andWhere('t.inactiveDate IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('t.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('t.inactiveDate IS NULL');
        }
    }
}
