<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentRound;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizTournamentRound>
 */
class QuizTournamentRoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournamentRound::class);
    }

    /**
     * The rounds of a tournament still open to joiners, the latest first - neither paired nor
     * cancelled (a round never expires by itself, §8).
     *
     * @return list<QuizTournamentRound>
     */
    public function findOpenForTournament(QuizTournament $tournament): array
    {
        /** @var list<QuizTournamentRound> $rounds */
        $rounds = $this->createQueryBuilder('r')
            ->where('r.tournament = :tournament')
            ->andWhere('r.pairedAt IS NULL AND r.cancelledAt IS NULL')
            ->setParameter('tournament', $tournament)
            ->orderBy('r.openedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rounds;
    }

    /** The round open for this class in this tournament, if any - one at a time (§8). */
    public function findOpenForProgram(QuizTournament $tournament, Program $program): ?QuizTournamentRound
    {
        /** @var QuizTournamentRound|null $round */
        $round = $this->createQueryBuilder('r')
            ->where('r.tournament = :tournament')
            ->andWhere('r.program = :program')
            ->andWhere('r.pairedAt IS NULL AND r.cancelledAt IS NULL')
            ->setParameter('tournament', $tournament)
            ->setParameter('program', $program)
            ->orderBy('r.openedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $round;
    }

    /**
     * The rounds this teacher opened in this tournament since a moment, cancelled ones left out,
     * the latest first - what the tournament page offers to project again.
     *
     * @return list<QuizTournamentRound>
     */
    public function findRecentForTeacher(QuizTournament $tournament, User $teacher, \DateTimeImmutable $since): array
    {
        /** @var list<QuizTournamentRound> $rounds */
        $rounds = $this->createQueryBuilder('r')
            ->addSelect('p')
            ->innerJoin('r.program', 'p')
            ->where('r.tournament = :tournament')
            ->andWhere('r.teacher = :teacher')
            ->andWhere('r.cancelledAt IS NULL')
            ->andWhere('r.openedAt >= :since')
            ->setParameter('tournament', $tournament)
            ->setParameter('teacher', $teacher)
            ->setParameter('since', $since)
            ->orderBy('r.openedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rounds;
    }

    /**
     * The rounds of this tournament a student took part in since a moment, paired ones, the latest
     * first - what tells them, once the round is paired, where their duel is or that they sit out.
     *
     * @return list<QuizTournamentRound>
     */
    public function findPairedSinceFor(QuizTournament $tournament, User $student, \DateTimeImmutable $since): array
    {
        /** @var list<QuizTournamentRound> $rounds */
        $rounds = $this->createQueryBuilder('r')
            ->innerJoin('r.participants', 'u')
            ->where('r.tournament = :tournament')
            ->andWhere('u = :student')
            ->andWhere('r.pairedAt >= :since')
            ->setParameter('tournament', $tournament)
            ->setParameter('student', $student)
            ->setParameter('since', $since)
            ->orderBy('r.pairedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rounds;
    }
}
