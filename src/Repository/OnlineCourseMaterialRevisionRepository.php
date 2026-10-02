<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineCourseMaterialRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OnlineCourseMaterialRevision>
 */
class OnlineCourseMaterialRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineCourseMaterialRevision::class);
    }
}
