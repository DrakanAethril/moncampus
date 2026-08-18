<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
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
