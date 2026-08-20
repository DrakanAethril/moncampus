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
     * The teacher's batches across several classes at once - the assignment creation wizard (2a)
     * loads those of all their classes, the class only being chosen at step 1.
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

    /**
     * The lots of this Program that OTHER teachers have shared with $teacher - the "Groupes
     * partagés avec moi" banner. Deliberately kept apart from findAllForTeacherAndProgram() rather
     * than folded into it with an OR: the two banners are two lists on screen, the shared one is
     * read-only, and the assignment wizard (findAllForTeacherAndPrograms) must keep offering the
     * teacher's own lots only.
     *
     * @return list<GroupBatch>
     */
    public function findAllSharedWithTeacherForProgram(User $teacher, Program $program): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin('b.sharedTeachers', 's')
            ->andWhere('s = :teacher')
            ->andWhere('b.program = :program')
            ->andWhere('b.teacher != :teacher')
            ->setParameter('teacher', $teacher)
            ->setParameter('program', $program)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What a teacher may target: the sets they saved, plus the ones a colleague shared with them.
     * The union is done in PHP rather than with an OR over the join, because an inner join on
     * sharedTeachers cannot express "or has no share at all" without turning into a left join whose
     * DISTINCT then fights the ordering.
     *
     * @param list<Program> $programs
     *
     * @return list<GroupBatch>
     */
    public function findAllReadableForTeacherAndPrograms(User $teacher, array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        $owned = $this->createQueryBuilder('b')
            ->andWhere('b.teacher = :teacher')
            ->andWhere('b.program IN (:programs)')
            ->setParameter('teacher', $teacher)
            ->setParameter('programs', $programs)
            ->getQuery()
            ->getResult();

        $shared = $this->createQueryBuilder('b')
            ->innerJoin('b.sharedTeachers', 's')
            ->andWhere('s = :teacher')
            ->andWhere('b.teacher != :teacher')
            ->andWhere('b.program IN (:programs)')
            ->setParameter('teacher', $teacher)
            ->setParameter('programs', $programs)
            ->getQuery()
            ->getResult();

        $all = array_merge($owned, $shared);
        usort($all, static fn (GroupBatch $a, GroupBatch $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $all;
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
