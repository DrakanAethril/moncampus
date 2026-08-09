<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainingApplicationAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingApplicationAttachment>
 */
class TrainingApplicationAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingApplicationAttachment::class);
    }
}
