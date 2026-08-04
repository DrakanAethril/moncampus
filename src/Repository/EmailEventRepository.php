<?php

namespace App\Repository;

use App\Entity\EmailEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailEvent>
 */
class EmailEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailEvent::class);
    }

    /** Test d'idempotence du worker « events », sur le même triplet que la contrainte unique. */
    public function alreadyStored(string $messageId, string $eventType, \DateTimeImmutable $occurredAt): bool
    {
        return null !== $this->findOneBy([
            'messageId' => $messageId,
            'eventType' => $eventType,
            'occurredAt' => $occurredAt,
        ]);
    }

    /** @return list<EmailEvent> */
    public function findForMessageId(string $messageId): array
    {
        return $this->findBy(['messageId' => $messageId], ['occurredAt' => 'ASC']);
    }
}
