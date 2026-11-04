<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelQuestion;
use App\Entity\QuizTournamentRound;
use App\Entity\User;
use App\Enum\QuizTournamentDuelOrigin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The facts the tournament's rules read about duels: activity and ladder for the standings (§6.2),
 * round duels for the daily cap of a pair (§4.1, §8), the questions recently seen for the draw
 * (§5.1), and whether a player is already in a duel (§4.3).
 *
 * @extends ServiceEntityRepository<QuizTournamentDuel>
 */
class QuizTournamentDuelRepository extends ServiceEntityRepository
{
    /** The draw leaves out the questions seen in this many of a player's last duels. */
    public const int RECENTLY_SEEN_DUELS = 3;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournamentDuel::class);
    }

    /** Has the tournament had its first duel? Until then everything of it may be edited (§3). */
    public function existsForTournament(QuizTournament $tournament): bool
    {
        return null !== $this->createQueryBuilder('d')
            ->select('d.id')
            ->where('d.tournament = :tournament')
            ->setParameter('tournament', $tournament)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * When the two met in a **round** duel in [$from, $to) - the challenges between them are counted
     * by QuizTournamentChallengeRepository::encounterTimes(), a challenge's duel being the same
     * encounter.
     *
     * @return list<\DateTimeImmutable>
     */
    public function roundEncounterTimes(QuizTournament $tournament, User $a, User $b, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{startedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.startedAt AS startedAt')
            ->where('d.tournament = :tournament')
            ->andWhere('d.origin = :round')
            ->andWhere('(d.playerA = :a AND d.playerB = :b) OR (d.playerA = :b AND d.playerB = :a)')
            ->andWhere('d.startedAt >= :from AND d.startedAt < :to')
            ->setParameter('tournament', $tournament)
            ->setParameter('round', QuizTournamentDuelOrigin::Round)
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): \DateTimeImmutable => $row['startedAt'], $rows);
    }

    /**
     * When this person met each other player in a **round** duel in [$from, $to) -
     * roundEncounterTimes() for every line of the standings at once.
     *
     * @return array<int, list<\DateTimeImmutable>> other user id => times
     */
    public function roundEncounterTimesByOpponent(QuizTournament $tournament, User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{a: int|string, b: int|string, startedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.playerA) AS a', 'IDENTITY(d.playerB) AS b', 'd.startedAt AS startedAt')
            ->where('d.tournament = :tournament')
            ->andWhere('d.origin = :round')
            ->andWhere('d.playerA = :user OR d.playerB = :user')
            ->andWhere('d.startedAt >= :from AND d.startedAt < :to')
            ->setParameter('tournament', $tournament)
            ->setParameter('round', QuizTournamentDuelOrigin::Round)
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        return QuizTournamentChallengeRepository::timesByOpponent($rows, (int) $user->getId(), 'startedAt');
    }

    /**
     * How many round duels each pair among these people played in [$from, $to), keyed
     * « smallerId-largerId » - added to QuizTournamentChallengeRepository::pairEncounterCounts().
     *
     * @param list<int> $userIds
     *
     * @return array<string, int>
     */
    public function roundPairEncounterCounts(QuizTournament $tournament, array $userIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (\count($userIds) < 2) {
            return [];
        }

        /** @var list<array{a: int|string, b: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.playerA) AS a', 'IDENTITY(d.playerB) AS b')
            ->where('d.tournament = :tournament')
            ->andWhere('d.origin = :round')
            ->andWhere('d.playerA IN (:ids) AND d.playerB IN (:ids)')
            ->andWhere('d.startedAt >= :from AND d.startedAt < :to')
            ->setParameter('tournament', $tournament)
            ->setParameter('round', QuizTournamentDuelOrigin::Round)
            ->setParameter('ids', $userIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        return QuizTournamentChallengeRepository::countPairs($rows);
    }

    /**
     * How many duels each player **finished** in [$since, $until) - the activity rule's « 3 duels sur
     * les 14 derniers jours ».
     *
     * @return array<int, int> user id => duels
     */
    public function finishedDuelCounts(QuizTournament $tournament, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $counts = [];
        foreach ($this->finishedRows($tournament, $since, $until) as $row) {
            foreach (['a', 'b'] as $side) {
                $id = (int) $row[$side];
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The players who finished, in [$since, $until), a duel stamped as a ladder duel for them - the
     * first half of the ladder rule (§6.2); QuizTournamentChallengeRepository::ladderDeclinedChallengerIds()
     * is the other.
     *
     * @return list<int>
     */
    public function ladderPlayerIds(QuizTournament $tournament, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $ids = [];
        foreach ($this->finishedRows($tournament, $since, $until) as $row) {
            if ($row['ladderA']) {
                $ids[(int) $row['a']] = true;
            }
            if ($row['ladderB']) {
                $ids[(int) $row['b']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The ratings before and after of every duel **finished** in [$since, $until) - what
     * App\Service\QuizTournament\QuizTournamentRatingTrend sums into « l'écart de cote sur 14 jours »
     * (§6.2). Same window as finishedDuelCounts(): a duel counts on the day it started.
     *
     * @return list<array{a: int, b: int, beforeA: int, afterA: int|null, beforeB: int, afterB: int|null}>
     */
    public function ratingMoves(QuizTournament $tournament, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        /** @var list<array{a: int|string, b: int|string, beforeA: int|string, afterA: int|string|null, beforeB: int|string, afterB: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select(
                'IDENTITY(d.playerA) AS a',
                'IDENTITY(d.playerB) AS b',
                'd.ratingBeforeA AS beforeA',
                'd.ratingAfterA AS afterA',
                'd.ratingBeforeB AS beforeB',
                'd.ratingAfterB AS afterB',
            )
            ->where('d.tournament = :tournament')
            ->andWhere('d.endedAt IS NOT NULL')
            ->andWhere('d.startedAt >= :since AND d.startedAt < :until')
            ->setParameter('tournament', $tournament)
            ->setParameter('since', $since)
            ->setParameter('until', $until)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'a' => (int) $row['a'],
            'b' => (int) $row['b'],
            'beforeA' => (int) $row['beforeA'],
            'afterA' => null === $row['afterA'] ? null : (int) $row['afterA'],
            'beforeB' => (int) $row['beforeB'],
            'afterB' => null === $row['afterB'] ? null : (int) $row['afterB'],
        ], $rows);
    }

    /**
     * The source questions this player saw in their last three duels of the tournament - what the
     * draw leaves out while the reserve allows (§5.1).
     *
     * @return list<int>
     */
    public function recentlySeenQuestionIds(QuizTournament $tournament, User $player): array
    {
        /** @var list<array{id: int|string}> $duels */
        $duels = $this->createQueryBuilder('d')
            ->select('d.id AS id')
            ->where('d.tournament = :tournament')
            ->andWhere('d.playerA = :player OR d.playerB = :player')
            ->setParameter('tournament', $tournament)
            ->setParameter('player', $player)
            ->orderBy('d.startedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults(self::RECENTLY_SEEN_DUELS)
            ->getQuery()
            ->getArrayResult();

        if ([] === $duels) {
            return [];
        }

        /** @var list<array{id: int|string|null}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(q.sourceQuestion) AS id')
            ->from(QuizTournamentDuelQuestion::class, 'q')
            ->where('q.duel IN (:duels)')
            ->andWhere('q.sourceQuestion IS NOT NULL')
            ->setParameter('duels', array_map(static fn (array $row): int => (int) $row['id'], $duels))
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The duel this person is playing right now, in any tournament - « un seul duel à la fois »
     * (§4.3).
     */
    public function findUnfinishedFor(User $player): ?QuizTournamentDuel
    {
        /** @var QuizTournamentDuel|null $duel */
        $duel = $this->createQueryBuilder('d')
            ->where('d.playerA = :player OR d.playerB = :player')
            ->andWhere('d.endedAt IS NULL')
            ->setParameter('player', $player)
            ->orderBy('d.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $duel;
    }

    /**
     * Among these people, those playing a duel right now, in any tournament - the round's pairing
     * skips them (« un seul duel à la fois », §4.3).
     *
     * @param list<int> $userIds
     *
     * @return list<int>
     */
    public function busyAmong(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{a: int|string, b: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.playerA) AS a', 'IDENTITY(d.playerB) AS b')
            ->where('d.endedAt IS NULL')
            ->andWhere('d.playerA IN (:ids) OR d.playerB IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getArrayResult();

        $busy = [];
        foreach ($rows as $row) {
            $busy[(int) $row['a']] = true;
            $busy[(int) $row['b']] = true;
        }

        return array_values(array_filter($userIds, static fn (int $id): bool => isset($busy[$id])));
    }

    /**
     * A round's duels, both players loaded, in the order they were made (by rating, §8).
     *
     * @return list<QuizTournamentDuel>
     */
    public function findForRound(QuizTournamentRound $round): array
    {
        /** @var list<QuizTournamentDuel> $duels */
        $duels = $this->createQueryBuilder('d')
            ->addSelect('a', 'b')
            ->innerJoin('d.playerA', 'a')
            ->innerJoin('d.playerB', 'b')
            ->where('d.round = :round')
            ->setParameter('round', $round)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $duels;
    }

    /**
     * Every duel not finished yet, in any tournament - what app:quiz-tournament:close-duels reads
     * (§5.2: a duel both players left runs out by itself).
     *
     * @return list<QuizTournamentDuel>
     */
    public function findUnfinished(): array
    {
        /** @var list<QuizTournamentDuel> $duels */
        $duels = $this->createQueryBuilder('d')
            ->where('d.endedAt IS NULL')
            ->orderBy('d.startedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $duels;
    }

    /**
     * One player's finished duels in a tournament, oldest first, both players loaded - « Mes duels »,
     * the rating line, and the duels a result counts before itself.
     *
     * @return list<QuizTournamentDuel>
     */
    public function findFinishedFor(QuizTournament $tournament, User $player): array
    {
        /** @var list<QuizTournamentDuel> $duels */
        $duels = $this->createQueryBuilder('d')
            ->addSelect('a', 'b')
            ->innerJoin('d.playerA', 'a')
            ->innerJoin('d.playerB', 'b')
            ->where('d.tournament = :tournament')
            ->andWhere('d.playerA = :player OR d.playerB = :player')
            ->andWhere('d.endedAt IS NOT NULL')
            ->setParameter('tournament', $tournament)
            ->setParameter('player', $player)
            ->orderBy('d.endedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $duels;
    }

    /**
     * @return list<array{a: int|string, b: int|string, ladderA: bool|int|string, ladderB: bool|int|string}>
     */
    private function finishedRows(QuizTournament $tournament, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        /** @var list<array{a: int|string, b: int|string, ladderA: bool|int|string, ladderB: bool|int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.playerA) AS a', 'IDENTITY(d.playerB) AS b', 'd.ladderA AS ladderA', 'd.ladderB AS ladderB')
            ->where('d.tournament = :tournament')
            ->andWhere('d.endedAt IS NOT NULL')
            ->andWhere('d.startedAt >= :since AND d.startedAt < :until')
            ->setParameter('tournament', $tournament)
            ->setParameter('since', $since)
            ->setParameter('until', $until)
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
