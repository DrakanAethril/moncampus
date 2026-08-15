<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\Grade;
use App\Entity\User;
use App\Enum\GradeStatus;
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

    /**
     * The grades one student actually holds, keyed by evaluation id - what an access condition on a
     * note is decided against (App\Service\AccessConditionFactsLoader), one query for a whole
     * screen rather than one per condition.
     *
     * Only a grade that counts is returned: an absent, not-evaluated or excluded student has no
     * note, and a missing key is read as "pas de note" rather than as a zero. The value is the raw
     * one, in the evaluation's own barème, which is the unit the teacher typed the threshold in.
     *
     * @param list<int> $evaluationIds
     *
     * @return array<int, float>
     */
    public function findValueByEvaluationIdForStudent(array $evaluationIds, User $student): array
    {
        if ([] === $evaluationIds) {
            return [];
        }

        /** @var list<array{evaluationId: int|string, value: float|string|null}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->select('IDENTITY(g.evaluation) AS evaluationId', 'g.value AS value')
            ->andWhere('g.evaluation IN (:evaluations)')
            ->andWhere('g.student = :student')
            ->andWhere('g.status = :status')
            ->andWhere('g.value IS NOT NULL')
            ->setParameter('evaluations', $evaluationIds)
            ->setParameter('student', $student)
            ->setParameter('status', GradeStatus::Normal)
            ->getQuery()
            ->getResult();

        $values = [];
        foreach ($rows as $row) {
            if (null !== $row['value']) {
                $values[(int) $row['evaluationId']] = (float) $row['value'];
            }
        }

        return $values;
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
