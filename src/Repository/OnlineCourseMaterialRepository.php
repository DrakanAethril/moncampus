<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineCourseMaterial;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OnlineCourseMaterial>
 */
class OnlineCourseMaterialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineCourseMaterial::class);
    }
}
