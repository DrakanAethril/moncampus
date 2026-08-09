<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainingApplicationReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingApplicationReview>
 */
class TrainingApplicationReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingApplicationReview::class);
    }
}
