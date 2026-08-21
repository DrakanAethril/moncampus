<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConsoleBroadcast;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConsoleBroadcast>
 */
class ConsoleBroadcastRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsoleBroadcast::class);
    }

    /**
     * How many broadcasts each of these sessions sent, keyed by session id.
     *
     * One query for the whole journal rather than one per row: the « Diffusions » column counts the
     * only thing done in a console that had an effect elsewhere, and it is on every line.
     *
     * @param list<int> $sessionIds
     *
     * @return array<int, int>
     */
    public function countBySession(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        $counts = [];

        /** @var list<array{session: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.session) AS session, COUNT(b.id) AS total')
            ->andWhere('b.session IN (:ids)')
            ->setParameter('ids', $sessionIds)
            ->groupBy('b.session')
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as $row) {
            $counts[(int) $row['session']] = (int) $row['total'];
        }

        return $counts;
    }
}
