<?php

namespace App\Repository;

use App\Entity\MessageThread;
use App\Entity\MessageThreadRecipient;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageThreadRecipient>
 */
class MessageThreadRecipientRepository extends ServiceEntityRepository
{
    public const string FOLDER_INBOX = 'inbox';
    public const string FOLDER_SENT = 'sent';
    public const string FOLDER_ARCHIVED = 'archived';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThreadRecipient::class);
    }

    // A user's own row for a thread - the Voter and every archive/delete/reply action work off
    // this, never a global lookup. Deliberately excludes already-deleted rows: once a participant
    // has soft-deleted their copy, they have no more standing on the thread until a new Message
    // resurrects it (see App\Controller\MessageController).
    public function findOneForUserAndThread(User $user, MessageThread $thread): ?MessageThreadRecipient
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->andWhere('r.thread = :thread')->setParameter('thread', $thread)
            ->andWhere('r.deletedAt IS NULL')
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Powers the nav badge (App\Twig\MessagingExtension) - Inbox only, same convention most mail
    // clients use (archived/sent items never count toward the unread badge).
    public function countUnreadForUser(User $user): int
    {
        return (int) $this->folderQueryBuilder($user, self::FOLDER_INBOX)
            ->select('COUNT(r.id)')
            ->andWhere('(r.lastReadAt IS NULL OR r.lastReadAt < t.lastMessageAt)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFolder(User $user, string $folder): int
    {
        return (int) $this->folderQueryBuilder($user, $folder)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<MessageThreadRecipient> */
    public function findFolderPage(User $user, string $folder, int $offset, int $limit): array
    {
        return $this->folderQueryBuilder($user, $folder)
            ->addSelect('t')->addSelect('s')
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // Every recipient row for a thread other than the sender's own - what "more than one
    // recipient" (App\Entity\MessageThread's announcement-shape rule) counts, and the denominator
    // for readStats() below.
    public function countRecipients(MessageThread $thread): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.thread = :thread')->setParameter('thread', $thread)
            ->andWhere('r.user != :sender')->setParameter('sender', $thread->getSender())
            ->getQuery()
            ->getSingleScalarResult();
    }

    // Read-count for the sender's own view of an announcement-shaped thread ("18/30 read") -
    // counts every non-sender recipient who has opened it at least once.
    /** @return array{total: int, read: int} */
    public function readStats(MessageThread $thread): array
    {
        $read = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.thread = :thread')->setParameter('thread', $thread)
            ->andWhere('r.user != :sender')->setParameter('sender', $thread->getSender())
            ->andWhere('r.lastReadAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return ['total' => $this->countRecipients($thread), 'read' => $read];
    }

    // Every participant row for a thread, deleted ones included - used when a new reply needs to
    // resurrect the thread for anyone who'd soft-deleted their copy (see
    // App\Controller\MessageController::reply()).
    /** @return list<MessageThreadRecipient> */
    public function findAllForThread(MessageThread $thread): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.thread = :thread')->setParameter('thread', $thread)
            ->getQuery()
            ->getResult();
    }

    private function folderQueryBuilder(User $user, string $folder): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.thread', 't')
            ->innerJoin('t.sender', 's')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->andWhere('r.deletedAt IS NULL');

        return match ($folder) {
            self::FOLDER_INBOX => $qb->andWhere('t.sender != :user')->andWhere('r.archivedAt IS NULL'),
            self::FOLDER_SENT => $qb->andWhere('t.sender = :user')->andWhere('r.archivedAt IS NULL'),
            self::FOLDER_ARCHIVED => $qb->andWhere('r.archivedAt IS NOT NULL'),
            default => throw new \InvalidArgumentException(\sprintf('Unknown folder "%s".', $folder)),
        };
    }
}
