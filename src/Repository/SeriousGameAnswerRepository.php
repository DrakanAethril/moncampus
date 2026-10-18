<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGameAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameAnswer>
 */
class SeriousGameAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameAnswer::class);
    }
}
