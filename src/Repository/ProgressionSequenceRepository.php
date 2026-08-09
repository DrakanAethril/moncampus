<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Progression;
use App\Entity\ProgressionSequence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgressionSequence>
 */
class ProgressionSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgressionSequence::class);
    }

    /** @return list<ProgressionSequence> */
    public function findOrderedForProgression(Progression $progression): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('si', 'se', 'pl', 'ls')
            ->innerJoin('s.sequenceInstance', 'si')
            ->leftJoin('s.seances', 'se')
            ->leftJoin('se.placements', 'pl')
            ->leftJoin('pl.lessonSession', 'ls')
            ->where('s.progression = :progression')
            ->setParameter('progression', $progression)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('se.position', 'ASC')
            ->addOrderBy('pl.partIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findNextPosition(Progression $progression): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->where('s.progression = :progression')
            ->setParameter('progression', $progression)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
