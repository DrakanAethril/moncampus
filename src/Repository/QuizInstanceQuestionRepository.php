<?php

namespace App\Repository;

use App\Entity\QuizInstanceQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizInstanceQuestion>
 */
class QuizInstanceQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizInstanceQuestion::class);
    }
}
