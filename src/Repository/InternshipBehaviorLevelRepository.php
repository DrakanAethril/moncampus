<?php

namespace App\Repository;

use App\Entity\InternshipBehaviorLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipBehaviorLevel>
 *
 * No standalone queries - levels are only ever read/written through their parent
 * InternshipBehaviorCriteria (see InternshipBehaviorCriteriaRepository::findAllActive()).
 */
class InternshipBehaviorLevelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipBehaviorLevel::class);
    }
}
