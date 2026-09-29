<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PortfolioEvidence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortfolioEvidence>
 */
class PortfolioEvidenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortfolioEvidence::class);
    }
}
