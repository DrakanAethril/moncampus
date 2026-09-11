<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Enum\IpAllocationOrigin;
use App\Enum\IpAllocationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IpAllocation>
 */
class IpAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpAllocation::class);
    }

    /**
     * Every address of a range that is still spoken for. This is what the allocator subtracts from
     * the window, so it must include reservations - an address held by a wizard somebody is still
     * filling in is not free.
     *
     * @return list<IpAllocation>
     */
    public function findLive(IpRange $range): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.range = :range')
            ->andWhere('a.status != :released')
            ->setParameter('range', $range)
            ->setParameter('released', IpAllocationStatus::Released)
            ->orderBy('a.ip', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> */
    public function findLiveAddresses(IpRange $range): array
    {
        /** @var list<array{ip: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.ip')
            ->andWhere('a.range = :range')
            ->andWhere('a.status != :released')
            ->setParameter('range', $range)
            ->setParameter('released', IpAllocationStatus::Released)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['ip'], $rows);
    }

    public function findLiveByAddress(IpRange $range, string $ip): ?IpAllocation
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.range = :range')
            ->andWhere('a.ip = :ip')
            ->andWhere('a.status != :released')
            ->setParameter('range', $range)
            ->setParameter('ip', $ip)
            ->setParameter('released', IpAllocationStatus::Released)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The whole registry of a range, including released rows, for the screen that shows its
     * history.
     *
     * @return list<IpAllocation>
     */
    public function findAllFor(IpRange $range, string $search = '', ?IpAllocationStatus $status = null, ?IpAllocationOrigin $origin = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.range = :range')
            ->setParameter('range', $range);

        if ('' !== $search) {
            $qb->andWhere('a.ip LIKE :search OR a.hostname LIKE :search OR a.note LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        } else {
            // Released rows are history, not registry: shown only when explicitly asked for.
            $qb->andWhere('a.status != :released')->setParameter('released', IpAllocationStatus::Released);
        }

        if (null !== $origin) {
            $qb->andWhere('a.origin = :origin')->setParameter('origin', $origin);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Reservations nothing ever came of - an abandoned wizard, a batch somebody walked away from.
     * Released by the cron so a range does not empty itself one abandoned step at a time.
     *
     * @return list<IpAllocation>
     */
    public function findStaleReservations(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :reserved')
            ->andWhere('a.operation IS NULL')
            ->andWhere('a.reservedAt < :before')
            ->setParameter('reserved', IpAllocationStatus::Reserved)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /**
     * The address a creation took. Used by the "what was created" screen, which is built from the
     * operation rather than from what was asked for - so it says what actually happened.
     */
    public function findOneByOperation(ProxmoxOperation $operation): ?IpAllocation
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.operation = :operation')
            ->setParameter('operation', $operation)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The address a machine is holding, by host and VMID.
     *
     * This is how anything that needs to *reach* a machine finds it: Proxmox stores no address that
     * MonCampus can query cheaply per guest, and the registry does. A machine created by hand has
     * no row here until the scan adopts one, which is correct - nothing here knows how to reach it
     * either.
     *
     * **A VMID names a slot, not a machine**, and the two rules below are what keeps that slot from
     * answering for its previous occupant:
     *
     *  - **scoped by host**, because a VMID is only unique within a cluster - two hypervisors both
     *    numbering a machine 9002 is the ordinary case, not an accident;
     *  - **latest wins**, because a number freed in Proxmox is handed out again. A registry row
     *    survives the machine that carried it (nothing here destroys machines, and the deletion
     *    happened in Proxmox), so a reused VMID has two live rows and the older one points at an
     *    address the new machine never had. Without the ordering the answer was whichever row MySQL
     *    happened to return first, which in practice was the dead one - and every account gesture
     *    then ran against the wrong address, reporting accounts as missing on a machine that has
     *    them. Same rule, same reason, as App\Repository\VmBatchItemRepository::findOneForMachine().
     */
    public function findAddressForVmid(?ProxmoxHost $host, int $vmid): ?string
    {
        if (null === $host) {
            return null;
        }

        /** @var list<array{ip: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.ip')
            ->join('a.range', 'r')
            ->andWhere('r.host = :host')
            ->andWhere('a.vmid = :vmid')
            ->andWhere('a.status != :released')
            ->setParameter('host', $host)
            ->setParameter('vmid', $vmid)
            ->setParameter('released', IpAllocationStatus::Released)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['ip'] ?? null;
    }

    /**
     * Every live registry row for one machine's number, newest first.
     *
     * What App\Service\Proxmox\VmidHandover sweeps: when a new machine takes a VMID, whatever is
     * still recorded under it belongs to the one that held it before.
     *
     * @return list<IpAllocation>
     */
    public function findLiveForVmid(ProxmoxHost $host, int $vmid): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.range', 'r')
            ->andWhere('r.host = :host')
            ->andWhere('a.vmid = :vmid')
            ->andWhere('a.status != :released')
            ->setParameter('host', $host)
            ->setParameter('vmid', $vmid)
            ->setParameter('released', IpAllocationStatus::Released)
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The addresses of several machines of one host at once, keyed by VMID.
     *
     * The plural of findAddressForVmid(), for « Mes machines virtuelles » and the machines list: a
     * machine declared outside a batch has no item to carry its address, so the registry is the
     * only place it is written - and asking machine by machine would put a query inside the loop.
     *
     * Scoped by host and newest-first for the reasons the singular states: a VMID is unique inside
     * one cluster and nowhere else, and a number handed out again answers for the machine holding
     * it now, never for the one it buried.
     *
     * @param list<int> $vmids
     *
     * @return array<int, string>
     */
    public function findAddressesForVmids(ProxmoxHost $host, array $vmids): array
    {
        if ([] === $vmids) {
            return [];
        }

        /** @var list<array{ip: string, vmid: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.ip AS ip', 'a.vmid AS vmid')
            ->join('a.range', 'r')
            ->andWhere('r.host = :host')
            ->andWhere('a.vmid IN (:vmids)')
            ->andWhere('a.status != :released')
            ->setParameter('host', $host)
            ->setParameter('vmids', $vmids)
            ->setParameter('released', IpAllocationStatus::Released)
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        $byVmid = [];

        foreach ($rows as $row) {
            // Newest first and first wins, like the singular's ORDER BY + setMaxResults(1): the
            // older rows of a reused VMID describe a machine that no longer exists.
            $byVmid[$row['vmid']] ??= $row['ip'];
        }

        return $byVmid;
    }

    public function countLive(IpRange $range): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.range = :range')
            ->andWhere('a.status != :released')
            ->setParameter('range', $range)
            ->setParameter('released', IpAllocationStatus::Released)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
