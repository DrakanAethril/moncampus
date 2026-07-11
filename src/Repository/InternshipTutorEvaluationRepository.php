<?php

namespace App\Repository;

use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Period;
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

    public function findOneForTutorLinkAndPeriod(InternshipTutorLink $tutorLink, Period $period): ?InternshipTutorEvaluation
    {
        return $this->findOneBy(['tutorLink' => $tutorLink, 'period' => $period]);
    }

    // Powers the tutor landing page's submitted/not-submitted status per period, without an
    // N+1 query per InternshipTutorLink shown.
    /** @return list<InternshipTutorEvaluation> */
    public function findAllForTutorLink(InternshipTutorLink $tutorLink): array
    {
        return $this->findBy(['tutorLink' => $tutorLink]);
    }

    // Powers the evaluation-reminder action - the ids returned here are diffed in PHP against
    // InternshipTutorLinkRepository::findAllActiveForProgram() to find which tutors still haven't
    // submitted for the chosen period. InternshipTutorEvaluation has no direct $program field
    // (only $tutorLink), hence the join.
    /** @return list<int> */
    public function findSubmittedTutorLinkIdsForProgramAndPeriod(Program $program, Period $period): array
    {
        $tutorLinkIds = $this->createQueryBuilder('te')
            ->select('IDENTITY(te.tutorLink) AS tutorLinkId')
            ->join('te.tutorLink', 'tl')
            ->where('tl.program = :program')
            ->andWhere('te.period = :period')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $tutorLinkIds);
    }
}
