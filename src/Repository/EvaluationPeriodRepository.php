<?php

namespace App\Repository;

use App\Entity\EvaluationPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationPeriod>
 */
class EvaluationPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationPeriod::class);
    }
}
