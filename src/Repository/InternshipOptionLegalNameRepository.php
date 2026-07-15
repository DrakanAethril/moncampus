<?php

namespace App\Repository;

use App\Entity\InternshipOptionLegalName;
use App\Entity\Option;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipOptionLegalName>
 */
class InternshipOptionLegalNameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipOptionLegalName::class);
    }

    /** @return array<int, string> Option id => overridden legal name */
    public function findMapForProgram(Program $program): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.option) AS optionId', 'm.legalName AS legalName')
            ->where('m.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['optionId']] = $row['legalName'];
        }

        return $map;
    }

    public function findOneForProgramAndOption(Program $program, Option $option): ?InternshipOptionLegalName
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
