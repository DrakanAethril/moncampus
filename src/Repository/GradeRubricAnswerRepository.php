<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\GradeRubricAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GradeRubricAnswer>
 */
class GradeRubricAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GradeRubricAnswer::class);
    }

    /**
     * Whether any point was entered against this evaluation's barème - which is what makes the barème
     * impossible to rebuild: the answers point at its questions through a foreign key with no ON
     * DELETE rule, and replacing the questions (App\Service\EvaluationRubricBuilder) would orphan
     * them. A row counts even with no points in it (« non évalué »): it is a row all the same.
     */
    public function existsForEvaluation(Evaluation $evaluation): bool
    {
        if (null === $evaluation->getId()) {
            return false;
        }

        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->join('a.question', 'q')
            ->join('q.section', 's')
            ->where('s.evaluation = :evaluation')
            ->setParameter('evaluation', $evaluation)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
