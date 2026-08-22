<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConsoleSession;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConsoleSession>
 */
class ConsoleSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsoleSession::class);
    }

    /**
     * The console this person already has open on this machine, if any.
     *
     * This is what makes « reprise » a fact rather than a promise: coming back to the same machine
     * rejoins the same row, and therefore the same tmux, rather than starting a second trace beside
     * a shell that was never a second shell.
     */
    public function findOpenFor(User $user, ProxmoxHost $host, string $node, int $vmid): ?ConsoleSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.openedBy = :user')
            ->andWhere('s.host = :host')
            ->andWhere('s.node = :node')
            ->andWhere('s.vmid = :vmid')
            ->andWhere('s.closedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('host', $host)
            ->setParameter('node', $node)
            ->setParameter('vmid', $vmid)
            ->orderBy('s.openedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every console currently open, anywhere.
     *
     * Read whole rather than counted, because the refusal at the ceiling **names who holds the
     * others** - an anonymous ceiling is a breakdown, a named one is a conversation.
     *
     * @return list<ConsoleSession>
     */
    public function findAllOpen(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.closedAt IS NULL')
            ->orderBy('s.openedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every session ever opened on one machine, oldest first.
     *
     * What the palette reads its « déjà passées sur cette machine » from: the transcripts are
     * already there, and a prompt line in one is a command somebody typed. Nothing extra is
     * recorded to make this work - which matters, since the one thing this feature must never do is
     * record keystrokes.
     *
     * @return list<ConsoleSession>
     */
    public function findForMachine(ProxmoxHost $host, string $node, int $vmid, int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.host = :host')
            ->andWhere('s.node = :node')
            ->andWhere('s.vmid = :vmid')
            ->andWhere('s.transcript IS NOT NULL')
            ->setParameter('host', $host)
            ->setParameter('node', $node)
            ->setParameter('vmid', $vmid)
            ->orderBy('s.openedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retention: sessions opened before this date, and the transcripts they carry.
     *
     * Deleted rather than emptied of their transcript: a row that says a console was opened three
     * months ago and shows nothing is a row nobody can do anything with, and the operations journal
     * already carries the fact that it happened (ProxmoxAction::Console).
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.openedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    public function countOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.openedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The journal: every session, newest first, filtered the way the screen filters.
     *
     * @return list<ConsoleSession>
     */
    public function findForJournal(?int $vmid, ?int $userId, \DateTimeImmutable $since, int $limit = 200): array
    {
        $builder = $this->createQueryBuilder('s')
            ->andWhere('s.openedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('s.openedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $vmid) {
            $builder->andWhere('s.vmid = :vmid')->setParameter('vmid', $vmid);
        }

        if (null !== $userId) {
            $builder->andWhere('IDENTITY(s.openedBy) = :user')->setParameter('user', $userId);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * The other people on this same machine right now - « Anne Dubois est aussi connectée ».
     *
     * Asked of the rows rather than of tmux: two clients on one session is the design, and knowing
     * who the other one is is something only the platform knows.
     *
     * @return list<ConsoleSession>
     */
    public function findOthersOnMachine(ConsoleSession $session, ProxmoxHost $host, string $node, int $vmid): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.host = :host')
            ->andWhere('s.node = :node')
            ->andWhere('s.vmid = :vmid')
            ->andWhere('s.closedAt IS NULL')
            ->andWhere('s.id <> :self')
            ->setParameter('host', $host)
            ->setParameter('node', $node)
            ->setParameter('vmid', $vmid)
            ->setParameter('self', $session->getId() ?? 0)
            ->getQuery()
            ->getResult();
    }
}
