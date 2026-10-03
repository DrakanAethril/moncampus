<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningPathStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningPathStep>
 */
class LearningPathStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningPathStep::class);
    }
}
