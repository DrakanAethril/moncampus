<?php

namespace App\Repository;

use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizAttempt>
 */
class QuizAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizAttempt::class);
    }

    /** @return list<QuizAttempt> */
    public function findForStudent(QuizInstance $instance, User $student): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.quizInstance = :instance')
            ->andWhere('a.student = :student')
            ->setParameter('instance', $instance)
            ->setParameter('student', $student)
            ->orderBy('a.attemptNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // The attempt currently in progress, if any - at most one at a time per student per instance
    // (a new one is only ever started once the previous is concluded, see
    // ProgramQuizAttemptController::take()).
    public function findInProgress(QuizInstance $instance, User $student): ?QuizAttempt
    {
        return $this->createQueryBuilder('a')
            ->where('a.quizInstance = :instance')
            ->andWhere('a.student = :student')
            ->andWhere('a.status IS NULL')
            ->setParameter('instance', $instance)
            ->setParameter('student', $student)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // "Score retenu = la dernière tentative" (design/design_campus_manager/README.md) - the most
    // recent *concluded* attempt, not the highest-scoring one.
    public function findLastConcluded(QuizInstance $instance, User $student): ?QuizAttempt
    {
        return $this->createQueryBuilder('a')
            ->where('a.quizInstance = :instance')
            ->andWhere('a.student = :student')
            ->andWhere('a.status IS NOT NULL')
            ->setParameter('instance', $instance)
            ->setParameter('student', $student)
            ->orderBy('a.attemptNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Powers the teacher-facing results screens (1f/1g) - every concluded attempt across every
    // student, in one query (student eagerly joined, since every row needs a display name).
    /** @return list<QuizAttempt> */
    public function findConcludedForInstance(QuizInstance $instance): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('s')
            ->join('a.student', 's')
            ->where('a.quizInstance = :instance')
            ->andWhere('a.status IS NOT NULL')
            ->setParameter('instance', $instance)
            ->orderBy('a.attemptNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
