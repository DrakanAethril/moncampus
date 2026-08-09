<?php

declare(strict_types=1);

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

    /**
     * Les lots de l'enseignant sur plusieurs classes à la fois - l'assistant de création d'un
     * travail (2a) charge ceux de toutes ses classes, la classe n'étant choisie qu'à l'étape 1.
     *
     * @param list<Program> $programs
     *
     * @return list<GroupBatch>
     */
    public function findAllForTeacherAndPrograms(User $teacher, array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('b')
            ->andWhere('b.teacher = :teacher')
            ->andWhere('b.program IN (:programs)')
            ->setParameter('teacher', $teacher)
            ->setParameter('programs', $programs)
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
