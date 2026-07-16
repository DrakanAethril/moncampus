<?php

namespace App\Repository;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipStudentEvaluation>
 */
class InternshipStudentEvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipStudentEvaluation::class);
    }

    public function findOneForStudentAndEvaluationPeriod(User $student, InternshipEvaluationPeriod $evaluationPeriod): ?InternshipStudentEvaluation
    {
        return $this->findOneBy(['student' => $student, 'evaluationPeriod' => $evaluationPeriod]);
    }

    // Powers the student's periods-list page's submitted/not-submitted status, without an N+1
    // query per evaluation period shown.
    /** @return list<InternshipStudentEvaluation> */
    public function findAllForStudentAndProgram(User $student, Program $program): array
    {
        return $this->findBy(['student' => $student, 'program' => $program]);
    }

    // Powers the evaluation-reminder action - the ids returned here are diffed in PHP against
    // Program::getStudents() to find who still hasn't submitted for the chosen evaluation period.
    /** @return list<int> */
    public function findSubmittedStudentIdsForProgramAndEvaluationPeriod(Program $program, InternshipEvaluationPeriod $evaluationPeriod): array
    {
        $studentIds = $this->createQueryBuilder('se')
            ->select('IDENTITY(se.student) AS studentId')
            ->where('se.program = :program')
            ->andWhere('se.evaluationPeriod = :evaluationPeriod')
            ->setParameter('program', $program)
            ->setParameter('evaluationPeriod', $evaluationPeriod)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $studentIds);
    }
}
