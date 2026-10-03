<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningPathEnrollment;
use App\Entity\LearningPathStepVisit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningPathStepVisit>
 */
class LearningPathStepVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningPathStepVisit::class);
    }

    /**
     * Every step these follow-ups opened - one query for a whole follow-up screen.
     *
     * @param list<LearningPathEnrollment> $enrollments
     *
     * @return list<LearningPathStepVisit>
     */
    public function findForEnrollments(array $enrollments): array
    {
        if ([] === $enrollments) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->andWhere('v.enrollment IN (:enrollments)')
            ->setParameter('enrollments', $enrollments)
            ->getQuery()
            ->getResult();
    }
}
