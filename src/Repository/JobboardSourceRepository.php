<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardSource>
 */
class JobboardSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardSource::class);
    }

    /**
     * Every source, by trade name. The table is read whole on purpose: the domains live in a JSON
     * column, a suffix match is not a `WHERE` any database would help with, and a couple of dozen
     * rows read once per request is not the cost worth engineering around.
     *
     * @return list<JobboardSource>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?JobboardSource
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
