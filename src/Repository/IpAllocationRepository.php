<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
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
     * The address a machine is holding, by VMID.
     *
     * This is how anything that needs to *reach* a machine finds it: Proxmox stores no address that
     * MonCampus can query cheaply per guest, and the registry does. A machine created by hand has
     * no row here until the scan adopts one, which is correct - nothing here knows how to reach it
     * either.
     */
    public function findAddressForVmid(int $vmid): ?string
    {
        /** @var list<array{ip: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.ip')
            ->andWhere('a.vmid = :vmid')
            ->andWhere('a.status != :released')
            ->setParameter('vmid', $vmid)
            ->setParameter('released', IpAllocationStatus::Released)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['ip'] ?? null;
    }

    /**
     * The addresses of several machines at once, keyed by VMID.
     *
     * The plural of findAddressForVmid(), for « Mes machines virtuelles »: a machine declared
     * outside a batch has no item to carry its address, so the registry is the only place it is
     * written - and asking machine by machine would put a query inside the loop.
     *
     * @param list<int> $vmids
     *
     * @return array<int, string>
     */
    public function findAddressesForVmids(array $vmids): array
    {
        if ([] === $vmids) {
            return [];
        }

        /** @var list<array{ip: string, vmid: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.ip AS ip', 'a.vmid AS vmid')
            ->andWhere('a.vmid IN (:vmids)')
            ->andWhere('a.status != :released')
            ->setParameter('vmids', $vmids)
            ->setParameter('released', IpAllocationStatus::Released)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byVmid = [];

        foreach ($rows as $row) {
            // First wins, like the singular's setMaxResults(1): a machine with two live
            // allocations is a registry to repair, not a card to draw twice.
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
