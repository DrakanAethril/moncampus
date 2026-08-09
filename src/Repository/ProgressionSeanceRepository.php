<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgressionSeance>
 */
class ProgressionSeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgressionSeance::class);
    }

    /** @return list<ProgressionSeance> */
    public function findOrderedForSequence(ProgressionSequence $sequence): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('pl', 'ls', 'o')
            ->leftJoin('s.placements', 'pl')
            ->leftJoin('pl.lessonSession', 'ls')
            ->leftJoin('pl.option', 'o')
            ->where('s.progressionSequence = :sequence')
            ->setParameter('sequence', $sequence)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('pl.partIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findNextPosition(ProgressionSequence $sequence): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->where('s.progressionSequence = :sequence')
            ->setParameter('sequence', $sequence)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
