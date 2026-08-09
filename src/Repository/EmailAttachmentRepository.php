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
     * Une pièce déjà stockée sous cette empreinte, quel que soit le message porteur : évite de
     * réécrire sur S3 un fichier identique octet pour octet (la même plaquette d'entreprise
     * envoyée à toute une promotion).
     */
    public function findOneByContentHash(string $contentHash): ?EmailAttachment
    {
        return $this->findOneBy(['contentHash' => $contentHash]);
    }
}
