<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StudentImportBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StudentImportBatch>
 */
class StudentImportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudentImportBatch::class);
    }

    /**
     * The « Imports récents » link on the users list. Recent rather than all: an import older than
     * the last few is history, and the screen it opens is about watching a queue drain.
     *
     * @return list<StudentImportBatch>
     */
    public function findRecent(int $limit = 5): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('p')
            ->leftJoin('b.program', 'p')
            ->orderBy('b.importedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
