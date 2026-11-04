<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\User;
use App\Enum\QuizTournamentChallengeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The facts App\Service\QuizTournament\QuizTournamentChallengeRules and the standings' ladder rule
 * read about challenges. « En attente » is a pending row whose 24 hours are not over: a row past its
 * expiry is expired whether or not anything rewrote its status.
 *
 * @extends ServiceEntityRepository<QuizTournamentChallenge>
 */
class QuizTournamentChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournamentChallenge::class);
    }

    public function countPendingOutgoing(QuizTournament $tournament, User $challenger, \DateTimeImmutable $now): int
    {
        return $this->countPending($tournament, 'challenger', $challenger, $now);
    }

    public function countPendingIncoming(QuizTournament $tournament, User $challenged, \DateTimeImmutable $now): int
    {
        return $this->countPending($tournament, 'challenged', $challenged, $now);
    }

    /** When the challenged last refused a challenge of this challenger, if ever. */
    public function lastRefusalAt(QuizTournament $tournament, User $challenger, User $challenged): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('c')
            ->select('MAX(c.respondedAt)')
            ->where('c.tournament = :tournament')
            ->andWhere('c.challenger = :challenger')
            ->andWhere('c.challenged = :challenged')
            ->andWhere('c.status = :refused')
            ->setParameter('tournament', $tournament)
            ->setParameter('challenger', $challenger)
            ->setParameter('challenged', $challenged)
            ->setParameter('refused', QuizTournamentChallengeStatus::Refused)
            ->getQuery()
            ->getSingleScalarResult();

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /**
     * When the two challenged each other in [$from, $to), either way and whatever became of it.
     *
     * @return list<\DateTimeImmutable>
     */
    public function encounterTimes(QuizTournament $tournament, User $a, User $b, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{createdAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.createdAt AS createdAt')
            ->where('c.tournament = :tournament')
            ->andWhere('(c.challenger = :a AND c.challenged = :b) OR (c.challenger = :b AND c.challenged = :a)')
            ->andWhere('c.createdAt >= :from AND c.createdAt < :to')
            ->setParameter('tournament', $tournament)
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): \DateTimeImmutable => $row['createdAt'], $rows);
    }

    /**
     * How many challenges each pair among these people exchanged in [$from, $to) - keyed
     * « smallerId-largerId », the pairs a round avoids once they reach the daily cap.
     *
     * @param list<int> $userIds
     *
     * @return array<string, int>
     */
    public function pairEncounterCounts(QuizTournament $tournament, array $userIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (\count($userIds) < 2) {
            return [];
        }

        /** @var list<array{a: int|string, b: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.challenger) AS a', 'IDENTITY(c.challenged) AS b')
            ->where('c.tournament = :tournament')
            ->andWhere('c.challenger IN (:ids) AND c.challenged IN (:ids)')
            ->andWhere('c.createdAt >= :from AND c.createdAt < :to')
            ->setParameter('tournament', $tournament)
            ->setParameter('ids', $userIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        return self::countPairs($rows);
    }

    /**
     * The challengers who, since $since, sent a **ladder** challenge (stamped QuizTournamentChallenge::$ladder)
     * that was refused or expired by $now - which holds the ladder rule for them (§6.2).
     *
     * @return list<int>
     */
    public function ladderDeclinedChallengerIds(QuizTournament $tournament, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT IDENTITY(c.challenger) AS id')
            ->where('c.tournament = :tournament')
            ->andWhere('c.ladder = true')
            ->andWhere('c.createdAt >= :since AND c.createdAt < :now')
            ->andWhere('c.status IN (:declined) OR (c.status = :pending AND c.expiresAt <= :now)')
            ->setParameter('tournament', $tournament)
            ->setParameter('since', $since)
            ->setParameter('now', $now)
            ->setParameter('declined', [QuizTournamentChallengeStatus::Refused, QuizTournamentChallengeStatus::Expired])
            ->setParameter('pending', QuizTournamentChallengeStatus::Pending)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Writes what QuizTournamentChallenge::statusAt() already reads: a pending challenge past its 24 h
     * (or its tournament's end) is expired, an accepted one whose room is over is missed. Two guarded
     * UPDATEs rather than loaded rows: a room that starts its duel in the same second keeps « played »
     * (`duel IS NULL`), whatever this pass read before. Returns how many rows moved.
     */
    public function settleLapsed(\DateTimeImmutable $now): int
    {
        $ended = $this->getEntityManager()->createQueryBuilder()
            ->select('t.id')
            ->from(QuizTournament::class, 't')
            ->where('t.endsAt <= :now')
            ->getDQL();

        $expired = $this->getEntityManager()->createQueryBuilder()
            ->update(QuizTournamentChallenge::class, 'c')
            ->set('c.status', ':expired')
            ->where('c.status = :pending')
            ->andWhere(\sprintf('c.expiresAt <= :now OR c.tournament IN (%s)', $ended))
            ->setParameter('expired', QuizTournamentChallengeStatus::Expired)
            ->setParameter('pending', QuizTournamentChallengeStatus::Pending)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        $missed = $this->getEntityManager()->createQueryBuilder()
            ->update(QuizTournamentChallenge::class, 'c')
            ->set('c.status', ':missed')
            ->set('c.challengerReadyAt', 'NULL')
            ->set('c.challengedReadyAt', 'NULL')
            ->where('c.status = :accepted')
            ->andWhere('c.duel IS NULL')
            ->andWhere(\sprintf('c.roomClosesAt <= :now OR c.tournament IN (%s)', $ended))
            ->setParameter('missed', QuizTournamentChallengeStatus::Missed)
            ->setParameter('accepted', QuizTournamentChallengeStatus::Accepted)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        return $expired + $missed;
    }

    /**
     * One person's challenges in a tournament, either side, that « Mes défis » may still show: those
     * still pending or accepted as stored (statusAt() decides what they are now), and any sent since
     * $since - the outcomes of the last day. Both users loaded.
     *
     * @return list<QuizTournamentChallenge>
     */
    public function findForBoard(QuizTournament $tournament, User $user, \DateTimeImmutable $since): array
    {
        /** @var list<QuizTournamentChallenge> $challenges */
        $challenges = $this->createQueryBuilder('c')
            ->addSelect('challenger', 'challenged')
            ->innerJoin('c.challenger', 'challenger')
            ->innerJoin('c.challenged', 'challenged')
            ->where('c.tournament = :tournament')
            ->andWhere('c.challenger = :user OR c.challenged = :user')
            ->andWhere('c.status IN (:open) OR c.createdAt >= :since')
            ->setParameter('tournament', $tournament)
            ->setParameter('user', $user)
            ->setParameter('open', [QuizTournamentChallengeStatus::Pending, QuizTournamentChallengeStatus::Accepted])
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        return $challenges;
    }

    /**
     * How many challenges each player of the tournament has pending, received - the « 5 at most »
     * of every line of the standings at once.
     *
     * @return array<int, int> user id => pending received
     */
    public function pendingIncomingCounts(QuizTournament $tournament, \DateTimeImmutable $now): array
    {
        /** @var list<array{id: int|string, pending: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.challenged) AS id', 'COUNT(c.id) AS pending')
            ->where('c.tournament = :tournament')
            ->andWhere('c.status = :pending')
            ->andWhere('c.expiresAt > :now')
            ->groupBy('c.challenged')
            ->setParameter('tournament', $tournament)
            ->setParameter('pending', QuizTournamentChallengeStatus::Pending)
            ->setParameter('now', $now)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['pending'];
        }

        return $counts;
    }

    /**
     * When each player last refused a challenge of this challenger - lastRefusalAt() for every line
     * of the standings at once.
     *
     * @return array<int, \DateTimeImmutable> challenged user id => last refusal
     */
    public function lastRefusalsOf(QuizTournament $tournament, User $challenger): array
    {
        /** @var list<array{id: int|string, at: string|null}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.challenged) AS id', 'MAX(c.respondedAt) AS at')
            ->where('c.tournament = :tournament')
            ->andWhere('c.challenger = :challenger')
            ->andWhere('c.status = :refused')
            ->groupBy('c.challenged')
            ->setParameter('tournament', $tournament)
            ->setParameter('challenger', $challenger)
            ->setParameter('refused', QuizTournamentChallengeStatus::Refused)
            ->getQuery()
            ->getArrayResult();

        $refusals = [];
        foreach ($rows as $row) {
            if (\is_string($row['at'])) {
                $refusals[(int) $row['id']] = new \DateTimeImmutable($row['at']);
            }
        }

        return $refusals;
    }

    /**
     * When this person and each other player challenged each other in [$from, $to), either way -
     * encounterTimes() for every line of the standings at once.
     *
     * @return array<int, list<\DateTimeImmutable>> other user id => times
     */
    public function encounterTimesByOpponent(QuizTournament $tournament, User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{a: int|string, b: int|string, createdAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.challenger) AS a', 'IDENTITY(c.challenged) AS b', 'c.createdAt AS createdAt')
            ->where('c.tournament = :tournament')
            ->andWhere('c.challenger = :user OR c.challenged = :user')
            ->andWhere('c.createdAt >= :from AND c.createdAt < :to')
            ->setParameter('tournament', $tournament)
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        return self::timesByOpponent($rows, (int) $user->getId(), 'createdAt');
    }

    /**
     * @param list<array<string, mixed>> $rows each with `a`, `b` (user ids) and a date under $dateKey
     *
     * @return array<int, list<\DateTimeImmutable>>
     */
    public static function timesByOpponent(array $rows, int $userId, string $dateKey): array
    {
        $times = [];
        foreach ($rows as $row) {
            $a = \is_numeric($row['a'] ?? null) ? (int) $row['a'] : 0;
            $b = \is_numeric($row['b'] ?? null) ? (int) $row['b'] : 0;
            $at = $row[$dateKey] ?? null;
            if (!$at instanceof \DateTimeImmutable) {
                continue;
            }
            $times[$a === $userId ? $b : $a][] = $at;
        }

        return $times;
    }

    /**
     * @param list<array{a: int|string, b: int|string}> $rows
     *
     * @return array<string, int>
     */
    public static function countPairs(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $a = (int) $row['a'];
            $b = (int) $row['b'];
            $key = min($a, $b).'-'.max($a, $b);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function countPending(QuizTournament $tournament, string $side, User $user, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.tournament = :tournament')
            ->andWhere(\sprintf('c.%s = :user', $side))
            ->andWhere('c.status = :pending')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('tournament', $tournament)
            ->setParameter('user', $user)
            ->setParameter('pending', QuizTournamentChallengeStatus::Pending)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
