<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Entity\VmBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestAccount>
 */
class GuestAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestAccount::class);
    }

    /**
     * Every account MonCampus knows about on one machine - identified by (host, node, vmid) rather
     * than by a relation, since no machine has a row here.
     *
     * @return list<GuestAccount>
     */
    public function findForMachine(ProxmoxHost $host, string $node, int $vmid): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.host = :host')
            ->andWhere('a.node = :node')
            ->andWhere('a.vmid = :vmid')
            ->setParameter('host', $host)
            ->setParameter('node', $node)
            ->setParameter('vmid', $vmid)
            ->orderBy('a.login', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForMachine(ProxmoxHost $host, string $node, int $vmid, string $login): ?GuestAccount
    {
        return $this->findOneBy(['host' => $host, 'node' => $node, 'vmid' => $vmid, 'login' => $login]);
    }

    /**
     * The logins declared on several machines of one host at once, keyed by `node/vmid`.
     *
     * The plural of findForMachine(), and it exists for « Mes machines virtuelles »: that screen
     * shows *every* account registered on a machine, not only the reader's own, so asking machine
     * by machine would put a query inside the loop over a class's worth of them. Scalars rather
     * than entities - the screen prints logins and nothing more.
     *
     * @param list<int> $vmids
     *
     * @return array<string, list<string>>
     */
    public function findLoginsOnMachines(ProxmoxHost $host, array $vmids): array
    {
        if ([] === $vmids) {
            return [];
        }

        /** @var list<array{node: string, vmid: int, login: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.node AS node', 'a.vmid AS vmid', 'a.login AS login')
            ->andWhere('a.host = :host')
            ->andWhere('a.vmid IN (:vmids)')
            ->setParameter('host', $host)
            ->setParameter('vmids', $vmids)
            ->orderBy('a.login', 'ASC')
            ->getQuery()
            ->getResult();

        $byMachine = [];

        foreach ($rows as $row) {
            // The VMID is filtered in SQL and the node sorted out here: a VMID is unique per
            // cluster, so the pair only ever narrows a set that is already the right one.
            $byMachine[\sprintf('%s/%d', $row['node'], $row['vmid'])][] = $row['login'];
        }

        return $byMachine;
    }

    /** @return list<GuestAccount> */
    /**
     * Whether this person holds an account on any machine at all.
     *
     * Asked by the navigation, on every authenticated page, to decide whether « Mes machines
     * virtuelles » is even offered - so it counts rather than loads, and stops at one. An entry
     * leading to « aucune machine » for every student of the school is an entry they learn to
     * ignore.
     */
    public function userHasAny(User $user): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every machine this person holds an account on.
     *
     * This is the whole of "mes machines virtuelles", and it needs no other rule: an account row is
     * created for each member of a machine, so a student who is on a group's machine and a student
     * who has one to themselves are the same query. Nothing here reads a batch's shape or a group's
     * membership - the accounts already are the answer, and reading it twice is how two answers
     * start to disagree.
     *
     * @return list<GuestAccount>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.host', 'h')->addSelect('h')
            ->leftJoin('a.batch', 'b')->addSelect('b')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.creationDate', 'DESC')
            ->addOrderBy('a.vmid', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findForBatch(VmBatch $batch): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.batch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('a.vmid', 'ASC')
            ->addOrderBy('a.login', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** How many machines of a host MonCampus holds accounts for - shown next to each row. */
    public function countByVmid(ProxmoxHost $host): array
    {
        /** @var list<array{vmid: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.vmid AS vmid, COUNT(a.id) AS total')
            ->andWhere('a.host = :host')
            ->setParameter('host', $host)
            ->groupBy('a.vmid')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['vmid']] = (int) $row['total'];
        }

        return $counts;
    }
}
