<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailAttachment>
 */
class EmailAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailAttachment::class);
    }

    /**
     * An attachment already stored under this fingerprint, whatever the carrying message: avoids
     * writing a byte-for-byte identical file to S3 again (the same company brochure sent to a whole
     * cohort).
     */
    public function findOneByContentHash(string $contentHash): ?EmailAttachment
    {
        return $this->findOneBy(['contentHash' => $contentHash]);
    }
}
