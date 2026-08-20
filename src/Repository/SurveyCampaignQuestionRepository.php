<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveyCampaignQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyCampaignQuestion>
 */
class SurveyCampaignQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyCampaignQuestion::class);
    }
}
