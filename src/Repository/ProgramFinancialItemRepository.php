<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\ProgramFinancialItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramFinancialItem>
 */
class ProgramFinancialItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramFinancialItem::class);
    }

    /** @return list<ProgramFinancialItem> */
    public function findAllForProgram(Program $program): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.lessonType', 'lt')->addSelect('lt')
            ->where('i.program = :program')
            ->setParameter('program', $program)
            ->orderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
