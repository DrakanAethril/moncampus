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
}
