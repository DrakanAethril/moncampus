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
