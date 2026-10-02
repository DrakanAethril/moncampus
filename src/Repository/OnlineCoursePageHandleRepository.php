<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineCoursePageHandle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OnlineCoursePageHandle>
 */
class OnlineCoursePageHandleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineCoursePageHandle::class);
    }
}
