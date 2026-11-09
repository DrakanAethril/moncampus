<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternshipReminderBatch;
use App\Entity\InternshipReminderBatchItem;
use App\Entity\InternshipTutorLink;
use App\Enum\ReminderBatchItemStatus;
use App\Enum\ReminderBatchStatus;
use App\Service\ReminderBatch\ReminderBatchTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipReminderBatchItem>
 */
class InternshipReminderBatchItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipReminderBatchItem::class);
    }

    /**
     * Who a running or paused batch still holds (R3): its lines not yet sent, keyed
     * « tutor link id|target key ». A line already sent holds nobody - the person may need
     * chasing again, and the next batch is how.
     *
     * @return array<string, true>
     */
    public function findQueuedKeys(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.tutorLink) AS tutorLinkId', 'b.step AS step', 'b.periodRank AS periodRank')
            ->join('i.batch', 'b')
            ->where('b.status IN (:active)')
            ->andWhere('i.status IN (:waiting)')
            ->setParameter('active', [ReminderBatchStatus::Running->value, ReminderBatchStatus::Paused->value])
            ->setParameter('waiting', array_map(static fn (ReminderBatchItemStatus $status): string => $status->value, ReminderBatchItemStatus::waiting()))
            ->getQuery()
            ->getResult();

        $keys = [];
        foreach ($rows as $row) {
            $rank = null === $row['periodRank'] ? null : (int) $row['periodRank'];
            $keys[self::queuedKey((int) $row['tutorLinkId'], new ReminderBatchTarget($row['step'], $rank))] = true;
        }

        return $keys;
    }

    public static function queuedKey(int $tutorLinkId, ReminderBatchTarget $target): string
    {
        return $tutorLinkId.'|'.$target->key();
    }

    /**
     * The line of a running or paused batch still to send to this alternance - what the alternance's
     * page warns about before a reminder by hand would make it two mails.
     */
    public function findQueuedForTutorLink(InternshipTutorLink $tutorLink): ?InternshipReminderBatchItem
    {
        return $this->createQueryBuilder('i')
            ->addSelect('b')
            ->join('i.batch', 'b')
            ->where('i.tutorLink = :tutorLink')
            ->andWhere('b.status IN (:active)')
            ->andWhere('i.status IN (:waiting)')
            ->setParameter('tutorLink', $tutorLink)
            ->setParameter('active', [ReminderBatchStatus::Running->value, ReminderBatchStatus::Paused->value])
            ->setParameter('waiting', array_map(static fn (ReminderBatchItemStatus $status): string => $status->value, ReminderBatchItemStatus::waiting()))
            ->orderBy('b.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every batch's figures in one query - a GROUP BY over the lines, never a stored counter.
     *
     * @param list<InternshipReminderBatch> $batches
     *
     * @return array<int, array<string, int>> batch id => status value => lines
     */
    public function countsByBatch(array $batches): array
    {
        if ([] === $batches) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.batch) AS batchId', 'i.status AS status', 'COUNT(i.id) AS total')
            ->where('i.batch IN (:batches)')
            ->setParameter('batches', $batches)
            ->groupBy('i.batch', 'i.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['batchId']][$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, int>> program id => status value => lines
     */
    public function countsByProgram(InternshipReminderBatch $batch): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.program) AS programId', 'i.status AS status', 'COUNT(i.id) AS total')
            ->where('i.batch = :batch')
            ->setParameter('batch', $batch)
            ->groupBy('i.program', 'i.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['programId']][$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The lines of a batch as the screen lists them: by formation, then the alternant's name.
     *
     * @param list<ReminderBatchItemStatus> $statuses empty = every line
     *
     * @return list<InternshipReminderBatchItem>
     */
    public function findForBatch(InternshipReminderBatch $batch, array $statuses = []): array
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('l', 'st', 'tu', 'p', 'ep')
            ->join('i.tutorLink', 'l')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.tutor', 'tu')
            ->join('i.program', 'p')
            ->leftJoin('i.evaluationPeriod', 'ep')
            ->where('i.batch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('p.shortName', 'ASC')
            ->addOrderBy('st.lastname', 'ASC')
            ->addOrderBy('st.firstname', 'ASC')
            ->addOrderBy('i.id', 'ASC');

        if ([] !== $statuses) {
            $qb->andWhere('i.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (ReminderBatchItemStatus $status): string => $status->value, $statuses));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The next lines to send of one batch, in the order they were frozen.
     *
     * @return list<InternshipReminderBatchItem>
     */
    public function findPending(InternshipReminderBatch $batch, int $limit): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.batch = :batch')
            ->andWhere('i.status = :pending')
            ->setParameter('batch', $batch)
            ->setParameter('pending', ReminderBatchItemStatus::Pending->value)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Lines a pass claimed and never settled. The pass is locked, so at the start of another one
     * any such line belongs to a pass that died between « En envoi » and SES's answer.
     *
     * @return list<InternshipReminderBatchItem>
     */
    public function findSending(): array
    {
        return $this->findBy(['status' => ReminderBatchItemStatus::Sending]);
    }

    public function countPending(InternshipReminderBatch $batch): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.batch = :batch')
            ->andWhere('i.status IN (:waiting)')
            ->setParameter('batch', $batch)
            ->setParameter('waiting', array_map(static fn (ReminderBatchItemStatus $status): string => $status->value, ReminderBatchItemStatus::waiting()))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many lines each running batch still has to send - what the estimated end of a batch is
     * computed from, the older batches going first.
     *
     * @return array<int, int> batch id => lines pending or being sent
     */
    public function countWaitingByRunningBatch(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.batch) AS batchId', 'COUNT(i.id) AS total')
            ->join('i.batch', 'b')
            ->where('b.status = :running')
            ->andWhere('i.status IN (:waiting)')
            ->setParameter('running', ReminderBatchStatus::Running->value)
            ->setParameter('waiting', array_map(static fn (ReminderBatchItemStatus $status): string => $status->value, ReminderBatchItemStatus::waiting()))
            ->groupBy('i.batch')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['batchId']] = (int) $row['total'];
        }

        return $counts;
    }
}
