<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EngagementDeclarationAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EngagementDeclarationAttachment>
 */
class EngagementDeclarationAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EngagementDeclarationAttachment::class);
    }
}
