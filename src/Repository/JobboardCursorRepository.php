<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardCursor;
use App\Entity\JobboardSource;
use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardCursor>
 */
class JobboardCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardCursor::class);
    }

    /** @return list<JobboardCursor> */
    public function findForTrack(Track $track): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.track = :track')
            ->setParameter('track', $track)
            ->leftJoin('c.source', 's')
            ->addSelect('s')
            ->orderBy('s.label', 'ASC')
            ->addOrderBy('c.search', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByScope(Track $track, JobboardSource $source, string $search): ?JobboardCursor
    {
        return $this->findOneBy(['track' => $track, 'source' => $source, 'search' => $search]);
    }

    /**
     * How many cursors each source carries, keyed by source id - one query for the whole
     * « Sources » screen rather than one per row.
     *
     * @return array<int, int>
     */
    public function countBySource(): array
    {
        $counts = [];

        /** @var array{source: int, total: int} $row */
        foreach ($this->createQueryBuilder('c')
            ->select('IDENTITY(c.source) AS source, COUNT(c.id) AS total')
            ->groupBy('c.source')
            ->getQuery()
            ->getResult() as $row) {
            $counts[(int) $row['source']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return list<JobboardCursor> */
    public function findForSource(JobboardSource $source): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->getResult();
    }
}
