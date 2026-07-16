<?php

namespace App\Repository;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipTutorEvaluation>
 */
class InternshipTutorEvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipTutorEvaluation::class);
    }

    public function findOneForTutorLinkAndEvaluationPeriod(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $evaluationPeriod): ?InternshipTutorEvaluation
    {
        return $this->findOneBy(['tutorLink' => $tutorLink, 'evaluationPeriod' => $evaluationPeriod]);
    }

    // Powers the tutor landing page's submitted/not-submitted status per evaluation period,
    // without an N+1 query per InternshipTutorLink shown.
    /** @return list<InternshipTutorEvaluation> */
    public function findAllForTutorLink(InternshipTutorLink $tutorLink): array
    {
        return $this->findBy(['tutorLink' => $tutorLink]);
    }

    // Powers the evaluation-reminder action and the staff evaluation-status screen - the ids
    // returned here are diffed in PHP against InternshipTutorLinkRepository::
    // findAllActiveForProgram() to find which tutors still haven't submitted for the chosen
    // evaluation period. InternshipTutorEvaluation has no direct $program field (only
    // $tutorLink), hence the join.
    /** @return list<int> */
    public function findSubmittedTutorLinkIdsForProgramAndEvaluationPeriod(Program $program, InternshipEvaluationPeriod $evaluationPeriod): array
    {
        $tutorLinkIds = $this->createQueryBuilder('te')
            ->select('IDENTITY(te.tutorLink) AS tutorLinkId')
            ->join('te.tutorLink', 'tl')
            ->where('tl.program = :program')
            ->andWhere('te.evaluationPeriod = :evaluationPeriod')
            ->setParameter('program', $program)
            ->setParameter('evaluationPeriod', $evaluationPeriod)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $tutorLinkIds);
    }
}
