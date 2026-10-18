<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGameProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameProgress>
 */
class SeriousGameProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameProgress::class);
    }
}
