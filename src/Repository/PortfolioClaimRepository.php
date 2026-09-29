<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PortfolioClaim;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortfolioClaim>
 */
class PortfolioClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortfolioClaim::class);
    }
}
