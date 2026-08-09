<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipTeamEvaluation;
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

    // Powers the teacher dashboard's "des livrets attendent vos remarques" banner
    // (design_handoff_dashboards ens-b): an équipe pédagogique step is open exactly when the
    // alternant has signed and no signed team evaluation exists yet for that (student, period) -
    // same gate as AlternancePeriodWizardService::isTeamStepOpen(), fetched in one query across
    // every Program the teacher belongs to (student + period joined for the banner's own text).
    /** @param list<Program> $programs @return list<InternshipStudentEvaluation> */
    public function findSignedAwaitingTeamForPrograms(array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('se')
            ->addSelect('st', 'ep')
            ->innerJoin('se.student', 'st')
            ->innerJoin('se.evaluationPeriod', 'ep')
            ->where('se.program IN (:programs)')
            ->andWhere('se.signedAt IS NOT NULL')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s te WHERE te.student = se.student AND te.evaluationPeriod = se.evaluationPeriod AND te.signedAt IS NOT NULL)',
                InternshipTeamEvaluation::class,
            ))
            ->setParameter('programs', $programs)
            ->orderBy('ep.endDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
