<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EvaluationPeriod;
use App\Entity\GamePeriodScore;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GamePeriodScore>
 */
class GamePeriodScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GamePeriodScore::class);
    }

    public function findOneFor(User $student, Program $program, EvaluationPeriod $period): ?GamePeriodScore
    {
        return $this->findOneBy(['student' => $student, 'program' => $program, 'period' => $period]);
    }

    /** Whether this period has already been closed for this formation - what makes the command idempotent. */
    public function isClosed(Program $program, EvaluationPeriod $period): bool
    {
        return null !== $this->createQueryBuilder('s')
            ->select('s.id')
            ->where('s.program = :program')
            ->andWhere('s.period = :period')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The frozen ranking of one closed period, best first - the révélation reads this and nothing
     * else, so it can never disagree with what was paid.
     *
     * @return list<GamePeriodScore>
     */
    public function ranking(Program $program, EvaluationPeriod $period): array
    {
        /** @var list<GamePeriodScore> $scores */
        $scores = $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->andWhere('s.period = :period')
            ->orderBy('s.indexValue', 'DESC')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();

        return $scores;
    }

    /**
     * Every closed period of one student, oldest first - the history behind the level.
     *
     * @return list<GamePeriodScore>
     */
    public function historyFor(User $student): array
    {
        /** @var list<GamePeriodScore> $scores */
        $scores = $this->createQueryBuilder('s')
            ->where('s.student = :student')
            ->orderBy('s.closedAt', 'ASC')
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return $scores;
    }
}
