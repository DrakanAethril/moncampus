<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\ProgramPeriodGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramPeriodGroup>
 */
class ProgramPeriodGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramPeriodGroup::class);
    }

    /** @return list<ProgramPeriodGroup> */
    public function findAllForProgramOrderedByPriority(Program $program): array
    {
        return $this->createQueryBuilder('ppg')
            ->join('ppg.periodGroup', 'pg')->addSelect('pg')
            ->join('pg.schoolYear', 'sy')->addSelect('sy')
            ->where('ppg.program = :program')
            ->setParameter('program', $program)
            ->orderBy('ppg.priority', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // 1-based - the first group attached to a Program gets priority 1 (most important); each
    // subsequent one is appended at the end (least important) until reordered via drag-and-drop.
    public function findNextPriorityForProgram(Program $program): int
    {
        $max = $this->createQueryBuilder('ppg')
            ->select('MAX(ppg.priority)')
            ->where('ppg.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 1 : ((int) $max + 1);
    }
}
