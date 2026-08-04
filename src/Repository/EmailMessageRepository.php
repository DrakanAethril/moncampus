<?php

namespace App\Repository;

use App\Entity\EmailMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailMessage>
 */
class EmailMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailMessage::class);
    }

    public function findOneByMessageId(string $messageId): ?EmailMessage
    {
        return $this->findOneBy(['messageId' => $messageId]);
    }

    public function findOneBySourceKey(string $sourceKey): ?EmailMessage
    {
        return $this->findOneBy(['sourceKey' => $sourceKey]);
    }

    /**
     * Le test d'idempotence du worker entrant, exécuté avant tout travail coûteux (téléchargement,
     * parsing, écriture S3). Interroge les deux clés parce qu'une relivraison SQS peut survenir
     * aussi bien sur un message dont on a su lire le Message-ID que sur un message malformé pour
     * lequel seule la clé S3 fait foi.
     */
    public function alreadyStored(?string $messageId, string $sourceKey): bool
    {
        if (null !== $messageId && null !== $this->findOneByMessageId($messageId)) {
            return true;
        }

        return null !== $this->findOneBySourceKey($sourceKey);
    }
}
