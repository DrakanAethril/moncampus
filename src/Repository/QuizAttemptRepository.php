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

    /**
     * Every concluded attempt one student made across a whole set of instances, in one query -
     * the student's "Travail à faire" screen weighs each quiz assignment against its target, and
     * would otherwise run a query per row.
     *
     * @param list<QuizInstance> $instances
     *
     * @return array<int, list<QuizAttempt>> instance id => their concluded attempts, oldest first
     */
    public function findConcludedByInstanceForStudent(array $instances, User $student): array
    {
        if ([] === $instances) {
            return [];
        }

        $attempts = $this->createQueryBuilder('a')
            ->where('a.quizInstance IN (:instances)')
            ->andWhere('a.student = :student')
            ->andWhere('a.status IS NOT NULL')
            ->setParameter('instances', $instances)
            ->setParameter('student', $student)
            ->orderBy('a.attemptNumber', 'ASC')
            ->getQuery()
            ->getResult();

        $byInstanceId = [];
        foreach ($attempts as $attempt) {
            $byInstanceId[$attempt->getQuizInstance()->getId()][] = $attempt;
        }

        return $byInstanceId;
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

    /**
     * Combien d'étudiants distincts ont terminé le quiz, quiz par quiz - l'avancement « n / m ont
     * répondu » de la liste des travaux (2b). Des étudiants, pas des passations : repasser un quiz
     * ne fait pas deux répondants.
     *
     * @param list<QuizInstance> $instances
     *
     * @return array<int, int> identifiant du quiz => nombre de répondants
     */
    public function countRespondentsByInstance(array $instances): array
    {
        if ([] === $instances) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.quizInstance) AS instanceId', 'COUNT(DISTINCT a.student) AS total')
            ->where('a.quizInstance IN (:instances)')
            ->andWhere('a.status IS NOT NULL')
            ->groupBy('a.quizInstance')
            ->setParameter('instances', $instances)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['instanceId']] = (int) $row['total'];
        }

        return $counts;
    }
}
