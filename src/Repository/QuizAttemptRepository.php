<?php

declare(strict_types=1);

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
     * How many distinct students finished the quiz, quiz by quiz - the « n / m ont répondu » progress
     * of the assignment list (2b). Students, not attempts: retaking a quiz does not make two
     * respondents.
     *
     * @param list<QuizInstance> $instances
     *
     * @return array<int, int> quiz identifier => number of respondents
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

    /**
     * The best concluded score a student has on each of these quizzes - what an access condition
     * reads, in one query for however many quizzes a screen's conditions name.
     *
     * The maximum is taken in PHP rather than in SQL because a score is not stored: QuizAttempt
     * computes it from correctCount over questionTotal. An instance with no concluded attempt is
     * absent from the result, which is how the evaluator tells "not taken yet" from "taken badly".
     *
     * @param list<int> $instanceIds
     *
     * @return array<int, float> quiz instance id => best percentage
     */
    public function findBestPercentByInstanceIdForStudent(array $instanceIds, User $student): array
    {
        if ([] === $instanceIds) {
            return [];
        }

        /** @var list<array{instanceId: int|string, correctCount: int|null, questionTotal: int|null}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.quizInstance) AS instanceId', 'a.correctCount AS correctCount', 'a.questionTotal AS questionTotal')
            ->where('a.quizInstance IN (:instances)')
            ->andWhere('a.student = :student')
            ->andWhere('a.status IS NOT NULL')
            ->setParameter('instances', $instanceIds)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $best = [];
        foreach ($rows as $row) {
            $total = (int) $row['questionTotal'];

            if (0 === $total) {
                continue;
            }

            $percent = round((int) $row['correctCount'] / $total * 100, 1);
            $instanceId = (int) $row['instanceId'];
            $best[$instanceId] = max($best[$instanceId] ?? 0.0, $percent);
        }

        return $best;
    }
}
