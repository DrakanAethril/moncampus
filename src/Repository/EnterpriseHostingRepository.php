<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EnterpriseHosting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnterpriseHosting>
 */
class EnterpriseHostingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnterpriseHosting::class);
    }
}
