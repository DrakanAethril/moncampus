<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningPathEnrollment;
use App\Entity\LearningPathQuizAttempt;
use App\Entity\LearningPathStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningPathQuizAttempt>
 */
class LearningPathQuizAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningPathQuizAttempt::class);
    }

    /**
     * Every attempt of these follow-ups, oldest first - one query for a whole follow-up screen.
     *
     * @param list<LearningPathEnrollment> $enrollments
     *
     * @return list<LearningPathQuizAttempt>
     */
    public function findForEnrollments(array $enrollments): array
    {
        if ([] === $enrollments) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.enrollment IN (:enrollments)')
            ->setParameter('enrollments', $enrollments)
            ->orderBy('a.startedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The attempt somebody left unfinished on a step - picked up again rather than drawn anew. */
    public function findRunning(LearningPathEnrollment $enrollment, LearningPathStep $step): ?LearningPathQuizAttempt
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.enrollment = :enrollment')
            ->andWhere('a.step = :step')
            ->andWhere('a.finishedAt IS NULL')
            ->setParameter('enrollment', $enrollment)
            ->setParameter('step', $step)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
