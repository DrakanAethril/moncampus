<?php

namespace App\Repository;

use App\Entity\EcoCheckpointScan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcoCheckpointScan>
 */
class EcoCheckpointScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcoCheckpointScan::class);
    }
}
