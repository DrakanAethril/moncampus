<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameAnswer>
 */
class SeriousGameAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameAnswer::class);
    }

    /** Whether a version of a unit's template has served a question - it must then stay replayable. */
    public function hasServedVersion(SeriousGame $game, string $unit, int $version): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->innerJoin('a.session', 's')
            ->andWhere('s.game = :game')
            ->andWhere('a.unitCode = :unit')
            ->andWhere('a.templateVersion = :version')
            ->setParameter('game', $game)
            ->setParameter('unit', $unit)
            ->setParameter('version', $version)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
