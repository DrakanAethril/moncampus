<?php

declare(strict_types=1);

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

    /** Idempotence check of the « events » worker, on the same triple as the unique constraint. */
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
