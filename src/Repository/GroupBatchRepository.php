<?php

namespace App\Repository;

use App\Entity\GroupBatch;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupBatch>
 */
class GroupBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupBatch::class);
    }

    /** @return list<GroupBatch> */
    public function findAllForTeacherAndProgram(User $teacher, Program $program): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.teacher = :teacher')
            ->andWhere('b.program = :program')
            ->setParameter('teacher', $teacher)
            ->setParameter('program', $program)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForTeacherAndProgram(int $id, User $teacher, Program $program): ?GroupBatch
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.id = :id')
            ->andWhere('b.teacher = :teacher')
            ->andWhere('b.program = :program')
            ->setParameter('id', $id)
            ->setParameter('teacher', $teacher)
            ->setParameter('program', $program)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
