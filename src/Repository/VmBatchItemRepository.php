<?php

declare(strict_types=1);

namespace App\Repository;

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
            ->setParameter('statuses', [VmBatchItemStatus::Planned, VmBatchItemStatus::Failed])
            ->orderBy('i.position', 'ASC')
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
