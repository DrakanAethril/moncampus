<?php

namespace App\Repository;

use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipTutorLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipLivretEngagement>
 */
class InternshipLivretEngagementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipLivretEngagement::class);
    }

    public function findOneForTutorLink(InternshipTutorLink $tutorLink): ?InternshipLivretEngagement
    {
        return $this->findOneBy(['tutorLink' => $tutorLink]);
    }
}
