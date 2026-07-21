<?php

namespace App\Repository;

use App\Entity\GradeRubricAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GradeRubricAnswer>
 */
class GradeRubricAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GradeRubricAnswer::class);
    }
}
