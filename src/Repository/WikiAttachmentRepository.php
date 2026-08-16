<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WikiAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WikiAttachment>
 */
class WikiAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WikiAttachment::class);
    }
}
