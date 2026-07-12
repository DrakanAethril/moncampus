<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\SequenceInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SequenceInstance>
 */
class SequenceInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SequenceInstance::class);
    }

    /** @return list<SequenceInstance> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->setParameter('program', $program)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
