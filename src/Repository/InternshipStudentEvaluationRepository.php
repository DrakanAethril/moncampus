<?php

namespace App\Repository;

use App\Entity\InternshipStudentEvaluation;
use App\Entity\Period;
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

    public function findOneForStudentAndPeriod(User $student, Period $period): ?InternshipStudentEvaluation
    {
        return $this->findOneBy(['student' => $student, 'period' => $period]);
    }

    // Powers the student's periods-list page's submitted/not-submitted status, without an N+1
    // query per period shown.
    /** @return list<InternshipStudentEvaluation> */
    public function findAllForStudentAndProgram(User $student, Program $program): array
    {
        return $this->findBy(['student' => $student, 'program' => $program]);
    }

    // Powers the evaluation-reminder action - the ids returned here are diffed in PHP against
    // Program::getStudents() to find who still hasn't submitted for the chosen period.
    /** @return list<int> */
    public function findSubmittedStudentIdsForProgramAndPeriod(Program $program, Period $period): array
    {
        $studentIds = $this->createQueryBuilder('se')
            ->select('IDENTITY(se.student) AS studentId')
            ->where('se.program = :program')
            ->andWhere('se.period = :period')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $studentIds);
    }
}
