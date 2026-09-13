<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardBatch;
use App\Entity\JobboardToken;
use App\Service\JsonRequestPayload;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardBatch>
 *
 * @phpstan-type JobboardDayTally array{tokenId: int, day: string, passes: int, created: int, reviewed: int, closed: int, rejected: int, lastAt: \DateTimeImmutable}
 */
class JobboardBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardBatch::class);
    }

    /** @return list<JobboardBatch> */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s', 't', 'u')
            ->leftJoin('b.section', 's')
            ->leftJoin('b.token', 't')
            ->leftJoin('b.importedBy', 'u')
            ->orderBy('b.openedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The batch a late `POST /offers/close` belongs to: the token's most recent pass of the same
     * calendar day.
     *
     * The day is the unit because the history's own unit is the day - a token that opened three
     * batches this morning reads as one line, so which of the three carries the closures changes
     * nothing on screen. Nothing is opened here: a close call on a day with no pass is answered
     * by the caller, which opens one rather than letting the figure fall on the floor.
     */
    public function findLatestForTokenOn(JobboardToken $token, \DateTimeImmutable $day): ?JobboardBatch
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.token = :token')
            ->andWhere('b.openedAt >= :from')
            ->andWhere('b.openedAt < :to')
            ->setParameter('token', $token)
            ->setParameter('from', $day->setTime(0, 0))
            ->setParameter('to', $day->setTime(0, 0)->modify('+1 day'))
            ->orderBy('b.openedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The API side of the history: one row per (key, day), whatever the number of passes.
     *
     * Grouped in SQL rather than in PHP because this table only grows - a veille running three
     * times a day for a year is a thousand rows nobody would want to hydrate to print thirty
     * lines. The counts come back as strings from PDO, hence the casts.
     *
     * @return list<JobboardDayTally>
     */
    public function dayTallies(int $limit): array
    {
        $sql = <<<'SQL'
            SELECT b.token_id AS token_id,
                   DATE(b.opened_at) AS day,
                   COUNT(*) AS passes,
                   SUM(b.created_count) AS created,
                   SUM(b.reviewed_count) AS reviewed,
                   SUM(b.closed_count) AS closed,
                   SUM(b.rejected_count) AS rejected,
                   MAX(b.opened_at) AS last_at
            FROM jobboard_batch b
            WHERE b.token_id IS NOT NULL
            GROUP BY b.token_id, DATE(b.opened_at)
            ORDER BY day DESC, last_at DESC
            LIMIT :limit
            SQL;

        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['limit' => max(1, $limit)], ['limit' => ParameterType::INTEGER])
            ->fetchAllAssociative();

        $tallies = [];
        foreach ($rows as $row) {
            // Typed at the boundary rather than cast field by field: a driver row is `mixed`, and
            // the SUM()s in particular come back as strings on MySQL.
            $read = JsonRequestPayload::fromArray($row);

            $tallies[] = [
                'tokenId' => $read->int('token_id') ?? 0,
                'day' => $read->string('day'),
                'passes' => $read->int('passes') ?? 0,
                'created' => $read->int('created') ?? 0,
                'reviewed' => $read->int('reviewed') ?? 0,
                'closed' => $read->int('closed') ?? 0,
                'rejected' => $read->int('rejected') ?? 0,
                'lastAt' => new \DateTimeImmutable($read->string('last_at')),
            ];
        }

        return $tallies;
    }

    /**
     * The import side: one row per import, never grouped. An import is a gesture somebody made,
     * and two of them on the same afternoon are two events - which is exactly what a collecting
     * pass is not.
     *
     * @return list<JobboardBatch>
     */
    public function findImports(int $limit): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s', 'u')
            ->leftJoin('b.section', 's')
            ->leftJoin('b.importedBy', 'u')
            ->andWhere('b.token IS NULL')
            ->orderBy('b.openedAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
