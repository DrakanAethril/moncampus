<?php

namespace App\Repository;

use App\Entity\InternshipOptionExamModality;
use App\Entity\Option;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipOptionExamModality>
 */
class InternshipOptionExamModalityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipOptionExamModality::class);
    }

    /** @return array<int, string> Option id => overridden exam modality text */
    public function findMapForProgram(Program $program): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.option) AS optionId', 'm.examModalityText AS examModalityText')
            ->where('m.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['optionId']] = $row['examModalityText'];
        }

        return $map;
    }

    public function findOneForProgramAndOption(Program $program, Option $option): ?InternshipOptionExamModality
    {
        return $this->createQueryBuilder('m')
            ->where('m.program = :program')
            ->andWhere('m.option = :option')
            ->setParameter('program', $program)
            ->setParameter('option', $option)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
