<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainingApplicationVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingApplicationVersion>
 */
class TrainingApplicationVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingApplicationVersion::class);
    }
}
