<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGameUnitState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameUnitState>
 */
class SeriousGameUnitStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameUnitState::class);
    }
}
