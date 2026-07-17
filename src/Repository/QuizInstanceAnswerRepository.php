<?php

namespace App\Repository;

use App\Entity\QuizInstanceAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizInstanceAnswer>
 */
class QuizInstanceAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizInstanceAnswer::class);
    }
}
