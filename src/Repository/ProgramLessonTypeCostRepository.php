<?php

namespace App\Repository;

use App\Entity\LessonType;
use App\Entity\Program;
use App\Entity\ProgramLessonTypeCost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramLessonTypeCost>
 */
class ProgramLessonTypeCostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramLessonTypeCost::class);
    }

    /** @return array<int, string> LessonType id => overridden cost */
    public function findCostMapForProgram(Program $program): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.lessonType) AS lessonTypeId', 'c.cost AS cost')
            ->where('c.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['lessonTypeId']] = $row['cost'];
        }

        return $map;
    }

    public function findOneForProgramAndLessonType(Program $program, LessonType $lessonType): ?ProgramLessonTypeCost
    {
        return $this->createQueryBuilder('c')
            ->where('c.program = :program')
            ->andWhere('c.lessonType = :lessonType')
            ->setParameter('program', $program)
            ->setParameter('lessonType', $lessonType)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
