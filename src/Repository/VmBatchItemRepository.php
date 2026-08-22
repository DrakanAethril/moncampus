<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProxmoxHost;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\VmBatchItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VmBatchItem>
 */
class VmBatchItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VmBatchItem::class);
    }

    /**
     * The batch row that describes one machine, found from the machine rather than from its batch.
     *
     * Which is the direction the console needs: it is handed (host, node, vmid) and has to answer
     * « where does this machine live and what is it called », without knowing - or caring - which
     * deployment produced it. Scoped by the batch's host rather than by VMID alone, because a VMID
     * is only unique within a cluster.
     */
    public function findOneForMachine(ProxmoxHost $host, int $vmid): ?VmBatchItem
    {
        return $this->createQueryBuilder('i')
            ->join('i.batch', 'b')
            ->andWhere('b.host = :host')
            ->andWhere('i.vmid = :vmid')
            ->setParameter('host', $host)
            ->setParameter('vmid', $vmid)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Which batch each deployed machine belongs to, for the whole fleet at once.
     *
     * The machines list needs the answer for every row it draws, and asking it row by row would be
     * one query per machine on a screen that already waits on every hypervisor. Keyed by host and
     * then by VMID because a VMID is only unique within a cluster - the same reasoning that scopes
     * findOneForMachine() by host rather than by number alone.
     *
     * @return array<int, array<int, array{id: int, label: string}>> host id => vmid => batch
     */
    public function findBatchesByHostAndVmid(): array
    {
        /** @var list<array{hostId: int, vmid: int, batchId: int, batchLabel: string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(b.host) AS hostId', 'i.vmid AS vmid', 'b.id AS batchId', 'b.label AS batchLabel')
            ->join('i.batch', 'b')
            ->andWhere('i.vmid IS NOT NULL')
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];

        foreach ($rows as $row) {
            // Latest wins, the way findOneForMachine() orders by id DESC: a VMID reused by a later
            // deployment belongs to that one, not to the batch that first held the number.
            $map[$row['hostId']][$row['vmid']] = ['id' => $row['batchId'], 'label' => $row['batchLabel']];
        }

        return $map;
    }

    /**
     * The items a "resume" should try again - the ones that never started and the ones that failed.
     * Anything already created is left alone, which is what makes resuming safe to press twice.
     *
     * @return list<VmBatchItem>
     */
    public function findResumable(VmBatch $batch): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.batch = :batch')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('batch', $batch)
            ->setParameter('statuses', array_values(array_filter(
                VmBatchItemStatus::cases(),
                static fn (VmBatchItemStatus $status): bool => $status->isResumable(),
            )))
            // Turns, not position. A waiting item stays resumable and a failed one is re-attempted
            // on purpose, so ordering by position let five machines that could not progress hold
            // every slot of every pass while the sixth never began. Nulls sort first in ASC, which
            // is what is wanted: a machine that has never been attempted goes before a slow one.
            ->orderBy('i.lastAttemptAt', 'ASC')
            ->addOrderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, int> status value => count */
    public function countByStatus(VmBatch $batch): array
    {
        /** @var list<array{status: VmBatchItemStatus, total: int}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS total')
            ->andWhere('i.batch = :batch')
            ->setParameter('batch', $batch)
            ->groupBy('i.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }
}
