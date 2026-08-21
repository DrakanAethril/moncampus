<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveyCampaignAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyCampaignAnswer>
 */
class SurveyCampaignAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyCampaignAnswer::class);
    }
}
