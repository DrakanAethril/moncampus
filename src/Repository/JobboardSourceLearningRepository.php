<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardSourceLearning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardSourceLearning>
 */
class JobboardSourceLearningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardSourceLearning::class);
    }

    /**
     * The most recent gestures, whole. Ungrouped, unlike the deposits themselves: two passes of one
     * morning are one line of history because they are the same routine repeated, whereas learning
     * the same domain twice cannot happen - once attached, the host is simply known.
     *
     * Everything the screen prints is joined here: the row names a lot, a key or an importer and a
     * filière, and fetching them one by one would be a query per line.
     *
     * @return list<JobboardSourceLearning>
     */
    public function findLatest(int $limit): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('s', 'b', 'tr', 't', 'u')
            ->leftJoin('l.source', 's')
            ->leftJoin('l.batch', 'b')
            ->leftJoin('b.track', 'tr')
            ->leftJoin('b.token', 't')
            ->leftJoin('b.importedBy', 'u')
            ->orderBy('l.learnedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
