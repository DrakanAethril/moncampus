<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VmBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VmBatch>
 */
class VmBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VmBatch::class);
    }

    /** @return list<VmBatch> */
    public function findOrdered(bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.program', 'p')->addSelect('p')
            ->leftJoin('b.host', 'h')->addSelect('h')
            ->orderBy('b.creationDate', 'DESC');

        if (!$includeInactive) {
            $qb->andWhere('b.inactiveDate IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Batches whose date has passed and that have not been reminded about yet.
     *
     * Reminded once, not every day: a reminder that arrives daily is a reminder nobody reads, and
     * the batch is not doing any harm - the application destroys nothing, so an expired batch is a
     * note to a human rather than a countdown.
     *
     * @return list<VmBatch>
     */
    public function findExpiredNeedingReminder(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.inactiveDate IS NULL')
            ->andWhere('b.expiresAt IS NOT NULL')
            ->andWhere('b.expiresAt < :today')
            ->andWhere('b.remindedAt IS NULL')
            ->setParameter('today', $today)
            ->getQuery()
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.inactiveDate IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
