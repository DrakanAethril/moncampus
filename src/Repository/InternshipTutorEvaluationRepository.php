<?php

namespace App\Repository;

use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Period;
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
}
