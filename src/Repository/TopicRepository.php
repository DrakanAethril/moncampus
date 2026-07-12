<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\Topic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    // App\Controller\ProgramTimetableSettingsController - both are always-active-only, unpaged
    // full-table displays (a Program's own topic list is small), with DataTables/RowGroup doing
    // the actual grouping/sorting/hour-total calculation client-side, so this just needs a
    // sensible initial order (matching what the client-side sort will produce anyway) and
    // topicGroup/teacher/topicGroup.options eager-loaded to avoid an N+1 per row - the syllabus
    // page's per-Option hour totals need every topic's group's Option scoping.
    /** @return list<Topic> */
    public function findAllForProgramOrderedByTopicGroup(Program $program): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('g', 'te', 'go')
            ->innerJoin('t.topicGroup', 'g')
            ->leftJoin('g.options', 'go')
            ->leftJoin('t.teacher', 'te')
            ->where('t.program = :program')
            ->andWhere('t.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('g.name', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
