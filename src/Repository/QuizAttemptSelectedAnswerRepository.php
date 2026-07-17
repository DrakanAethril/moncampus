<?php

namespace App\Repository;

use App\Entity\QuizAttemptSelectedAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizAttemptSelectedAnswer>
 */
class QuizAttemptSelectedAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizAttemptSelectedAnswer::class);
    }
}
