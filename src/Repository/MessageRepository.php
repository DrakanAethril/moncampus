<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /** @return list<Message> */
    public function findForThread(MessageThread $thread): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.author', 'a')->addSelect('a')
            ->leftJoin('m.attachments', 'att')->addSelect('att')
            ->andWhere('m.thread = :thread')->setParameter('thread', $thread)
            ->orderBy('m.sentAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers the Inbox/Sent/Archived list's plain-text snippet - the thread's own lastMessageAt
    // already gives the timestamp (App\Entity\MessageThread), this is only for the body preview.
    public function findLatest(MessageThread $thread): ?Message
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.thread = :thread')->setParameter('thread', $thread)
            ->orderBy('m.sentAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
