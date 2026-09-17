<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipSupervisorEvaluation>
 */
class InternshipSupervisorEvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipSupervisorEvaluation::class);
    }

    public function findOneForTutorLinkAndEvaluationPeriod(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $evaluationPeriod): ?InternshipSupervisorEvaluation
    {
        return $this->findOneBy(['tutorLink' => $tutorLink, 'evaluationPeriod' => $evaluationPeriod]);
    }

    /**
     * Every closed team evaluation of one Program, as a two-level map - the third signature of
     * AlternanceSubmissionIndex, in one query.
     *
     * @return array<int, array<int, true>> tutor link id => evaluation period id => closed
     */
    public function findClosedPairsForProgram(Program $program): array
    {
        $rows = $this->createQueryBuilder('sv')
            ->select('IDENTITY(sv.tutorLink) AS tutorLinkId', 'IDENTITY(sv.evaluationPeriod) AS periodId')
            ->join('sv.tutorLink', 'tl')
            ->where('tl.program = :program')
            ->andWhere('sv.closedAt IS NOT NULL')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        $pairs = [];
        foreach ($rows as $row) {
            $pairs[(int) $row['tutorLinkId']][(int) $row['periodId']] = true;
        }

        return $pairs;
    }
}
