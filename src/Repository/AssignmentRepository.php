<?php

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assignment>
 */
class AssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    /** @return list<Assignment> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.program = :program')
            ->setParameter('program', $program)
            ->orderBy('a.dueDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
