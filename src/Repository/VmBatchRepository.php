<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VmBatch;
use App\Enum\VmBatchItemStatus;
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

    /**
     * The batches a deployment has been started on and that are not finished.
     *
     * **"Started" is the whole safety of this method.** It is what the console driver reads, and a
     * driver that took every batch with unfinished items would deploy machines nobody asked for -
     * a batch that is planned and never launched is a plan, not an instruction. So a batch
     * qualifies only once at least one of its machines has left `planned`, which no code path
     * reaches without somebody pressing « Déployer ».
     *
     * @return list<VmBatch>
     */
    public function findLive(): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.host', 'h')->addSelect('h')
            ->andWhere('b.inactiveDate IS NULL')
            ->andWhere('EXISTS (SELECT begun.id FROM App\\Entity\\VmBatchItem begun WHERE begun.batch = b AND begun.status != :planned)')
            ->andWhere('EXISTS (SELECT unfinished.id FROM App\\Entity\\VmBatchItem unfinished WHERE unfinished.batch = b AND unfinished.status != :provisioned)')
            ->setParameter('planned', VmBatchItemStatus::Planned)
            ->setParameter('provisioned', VmBatchItemStatus::Provisioned)
            ->orderBy('b.creationDate', 'ASC')
            ->getQuery()
            ->getResult();
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
