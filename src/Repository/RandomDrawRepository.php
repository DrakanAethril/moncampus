<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\RandomDraw;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RandomDraw>
 */
class RandomDrawRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RandomDraw::class);
    }

    /**
     * Ordered by creation, oldest first - deliberately NOT by $updatedAt, which every autosave
     * moves: the banner would then reshuffle itself under the teacher's cursor at each draw.
     *
     * @return list<RandomDraw>
     */
    public function findAllForTeacherAndProgram(User $teacher, Program $program): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.teacher = :teacher')
            ->andWhere('d.program = :program')
            ->setParameter('teacher', $teacher)
            ->setParameter('program', $program)
            ->orderBy('d.createdAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForTeacherAndProgram(int $id, User $teacher, Program $program): ?RandomDraw
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('d.teacher = :teacher')
            ->andWhere('d.program = :program')
            ->setParameter('id', $id)
            ->setParameter('teacher', $teacher)
            ->setParameter('program', $program)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
