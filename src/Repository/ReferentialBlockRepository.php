<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReferentialBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferentialBlock>
 */
class ReferentialBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferentialBlock::class);
    }
}
