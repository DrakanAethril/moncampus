<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CrmSchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmSchoolYear>
 */
class CrmSchoolYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmSchoolYear::class);
    }
}
