<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternshipReminderBatch;
use App\Enum\ReminderBatchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipReminderBatch>
 */
class InternshipReminderBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipReminderBatch::class);
    }

    /**
     * @param list<ReminderBatchStatus> $statuses
     *
     * @return list<InternshipReminderBatch>
     */
    public function findByStatuses(array $statuses): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('u')
            ->leftJoin('b.createdBy', 'u')
            ->where('b.status IN (:statuses)')
            ->setParameter('statuses', array_map(static fn (ReminderBatchStatus $status): string => $status->value, $statuses))
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The batches the pass serves, in the order it serves them: the oldest first.
     *
     * @return list<InternshipReminderBatch>
     */
    public function findRunningOldestFirst(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', ReminderBatchStatus::Running->value)
            ->orderBy('b.createdAt', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int> status value => batches
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.status AS status', 'COUNT(b.id) AS total')
            ->groupBy('b.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }
}
