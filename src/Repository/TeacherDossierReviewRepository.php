<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeacherDossierReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeacherDossierReview>
 */
class TeacherDossierReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherDossierReview::class);
    }
}
