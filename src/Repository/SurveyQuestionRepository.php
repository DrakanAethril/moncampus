<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveyQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyQuestion>
 */
class SurveyQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyQuestion::class);
    }
}
