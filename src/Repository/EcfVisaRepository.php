<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EcfVisa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcfVisa>
 */
class EcfVisaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcfVisa::class);
    }
}
