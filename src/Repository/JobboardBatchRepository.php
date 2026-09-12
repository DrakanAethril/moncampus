<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardBatch>
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
}
