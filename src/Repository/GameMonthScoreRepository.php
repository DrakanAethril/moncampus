<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameMonthScore;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameMonthScore>
 */
class GameMonthScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameMonthScore::class);
    }

    public function findOneFor(User $student, Program $program, string $monthKey): ?GameMonthScore
    {
        return $this->findOneBy(['student' => $student, 'program' => $program, 'monthKey' => $monthKey]);
    }

    /** Whether this month has already been closed for this formation - what makes the command idempotent. */
    public function isClosed(Program $program, string $monthKey): bool
    {
        return null !== $this->createQueryBuilder('s')
            ->select('s.id')
            ->where('s.program = :program')
            ->andWhere('s.monthKey = :month')
            ->setParameter('program', $program)
            ->setParameter('month', $monthKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every month this formation has already closed, most recent first - what the ranking's « back »
     * navigation walks through.
     *
     * @return list<string>
     */
    public function closedMonths(Program $program): array
    {
        /** @var list<array{monthKey: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.monthKey AS monthKey')
            ->where('s.program = :program')
            ->orderBy('s.monthKey', 'DESC')
            ->setParameter('program', $program)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['monthKey'], $rows);
    }

    /**
     * The frozen ranking of one closed month, best first - the révélation reads this and nothing
     * else, so it can never disagree with what was paid.
     *
     * @return list<GameMonthScore>
     */
    public function ranking(Program $program, string $monthKey): array
    {
        /** @var list<GameMonthScore> $scores */
        $scores = $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->andWhere('s.monthKey = :month')
            ->orderBy('s.indexValue', 'DESC')
            ->setParameter('program', $program)
            ->setParameter('month', $monthKey)
            ->getQuery()
            ->getResult();

        return $scores;
    }

    /**
     * Every closed period of one student, oldest first - the history behind the level.
     *
     * @return list<GameMonthScore>
     */
    public function historyFor(User $student): array
    {
        /** @var list<GameMonthScore> $scores */
        $scores = $this->createQueryBuilder('s')
            ->where('s.student = :student')
            ->orderBy('s.closedAt', 'ASC')
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return $scores;
    }
}
