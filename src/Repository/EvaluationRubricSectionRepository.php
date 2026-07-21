<?php

namespace App\Repository;

use App\Entity\EvaluationRubricSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationRubricSection>
 */
class EvaluationRubricSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationRubricSection::class);
    }
}
