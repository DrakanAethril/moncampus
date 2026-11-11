<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CrmModalityChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmModalityChange>
 */
class CrmModalityChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmModalityChange::class);
    }
}
