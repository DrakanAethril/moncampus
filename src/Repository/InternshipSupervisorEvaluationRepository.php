<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTutorLink;
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
}
