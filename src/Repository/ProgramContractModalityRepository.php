<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContractType;
use App\Entity\Program;
use App\Entity\ProgramContractModality;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramContractModality>
 */
class ProgramContractModalityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramContractModality::class);
    }

    public function findOneForProgramAndContractType(Program $program, ContractType $contractType): ?ProgramContractModality
    {
        return $this->createQueryBuilder('m')
            ->where('m.program = :program')
            ->andWhere('m.contractType = :contractType')
            ->setParameter('program', $program)
            ->setParameter('contractType', $contractType)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ProgramContractModality> Every Program currently overriding this ContractType, most recently updated first. */
    public function findAllForContractType(ContractType $contractType): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.contractType = :contractType')
            ->setParameter('contractType', $contractType)
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countForContractType(ContractType $contractType): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.contractType = :contractType')
            ->setParameter('contractType', $contractType)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
