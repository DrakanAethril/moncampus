<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineCoursePage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OnlineCoursePage>
 */
class OnlineCoursePageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineCoursePage::class);
    }

    public function findOneByOwner(User $owner): ?OnlineCoursePage
    {
        return $this->findOneBy(['owner' => $owner]);
    }
}
