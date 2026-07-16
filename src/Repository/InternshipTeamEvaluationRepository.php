<?php

namespace App\Repository;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTeamEvaluation;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipTeamEvaluation>
 */
class InternshipTeamEvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipTeamEvaluation::class);
    }

    public function findOneForStudentAndEvaluationPeriod(User $student, InternshipEvaluationPeriod $evaluationPeriod): ?InternshipTeamEvaluation
    {
        return $this->findOneBy(['student' => $student, 'evaluationPeriod' => $evaluationPeriod]);
    }

    // Powers the team-evaluations periods-list page's submitted/not-submitted status, without an
    // N+1 query per evaluation period shown.
    /** @return list<InternshipTeamEvaluation> */
    public function findAllForStudentAndProgram(User $student, Program $program): array
    {
        return $this->findBy(['student' => $student, 'program' => $program]);
    }
}
