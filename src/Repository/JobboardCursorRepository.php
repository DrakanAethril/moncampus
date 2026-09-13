<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardCursor;
use App\Entity\Track;
use App\Enum\JobboardSource;
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
            ->orderBy('c.source', 'ASC')
            ->addOrderBy('c.search', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByScope(Track $track, JobboardSource $source, string $search): ?JobboardCursor
    {
        return $this->findOneBy(['track' => $track, 'source' => $source, 'search' => $search]);
    }
}
