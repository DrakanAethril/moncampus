<?php

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\Grade;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Grade>
 */
class GradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Grade::class);
    }

    /** @return list<Grade> */
    public function findForEvaluation(Evaluation $evaluation): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.evaluation = :evaluation')
            ->setParameter('evaluation', $evaluation)
            ->leftJoin('g.rubricAnswers', 'ra')->addSelect('ra')
            ->leftJoin('g.audioComment', 'ac')->addSelect('ac')
            ->getQuery()
            ->getResult();
    }

    public function findOneForEvaluationAndStudent(Evaluation $evaluation, User $student): ?Grade
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.evaluation = :evaluation')
            ->andWhere('g.student = :student')
            ->setParameter('evaluation', $evaluation)
            ->setParameter('student', $student)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @param list<Evaluation> $evaluations */
    public function findForEvaluationsAndStudent(array $evaluations, User $student): array
    {
        if ([] === $evaluations) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->andWhere('g.evaluation IN (:evaluations)')
            ->andWhere('g.student = :student')
            ->setParameter('evaluations', $evaluations)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();
    }
}
