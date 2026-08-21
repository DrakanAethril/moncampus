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
