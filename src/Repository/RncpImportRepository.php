<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RncpImport;
use App\Enum\RncpImportState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RncpImport>
 */
class RncpImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RncpImport::class);
    }

    /**
     * The requests waiting for app:rncp:fetch. « Running » ones are taken again too: a worker
     * killed mid-download leaves them there, and the lock already keeps two passes apart.
     *
     * @return list<RncpImport>
     */
    public function findPending(): array
    {
        /** @var list<RncpImport> $rows */
        $rows = $this->createQueryBuilder('i')
            ->where('i.state IN (:states)')
            ->setParameter('states', [RncpImportState::Pending, RncpImportState::Running])
            ->orderBy('i.requestedAt', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<RncpImport> the latest requests, for the Référentiels tab */
    public function findRecent(int $limit = 10): array
    {
        /** @var list<RncpImport> $rows */
        $rows = $this->createQueryBuilder('i')
            ->orderBy('i.requestedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
