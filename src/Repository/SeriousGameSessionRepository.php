<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameSession;
use App\Entity\User;
use App\Enum\SeriousGameSessionMode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameSession>
 */
class SeriousGameSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameSession::class);
    }

    public function findOwned(int $id, User $player, SeriousGame $game): ?SeriousGameSession
    {
        return $this->findOneBy(['id' => $id, 'player' => $player, 'game' => $game]);
    }

    /** The best score of the final test played to the end, null when it never was. */
    public function bestFinal(User $player, SeriousGame $game): ?int
    {
        $best = $this->createQueryBuilder('s')
            ->select('MAX(s.score)')
            ->andWhere('s.player = :player')
            ->andWhere('s.game = :game')
            ->andWhere('s.mode = :mode')
            ->andWhere('s.endedAt IS NOT NULL')
            ->setParameter('player', $player)
            ->setParameter('game', $game)
            ->setParameter('mode', SeriousGameSessionMode::Final)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($best) ? (int) $best : null;
    }

    /**
     * A player's sessions played to the end, the latest first.
     *
     * @return list<SeriousGameSession>
     */
    public function findEndedFor(User $player, SeriousGame $game, int $limit = 200): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.player = :player')
            ->andWhere('s.game = :game')
            ->andWhere('s.endedAt IS NOT NULL')
            ->setParameter('player', $player)
            ->setParameter('game', $game)
            ->orderBy('s.endedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The players' sessions played to the end, for a class reading: player id => sessions.
     *
     * @param list<User> $players
     *
     * @return array<int, list<SeriousGameSession>>
     */
    public function findEndedForPlayers(array $players, SeriousGame $game): array
    {
        if ([] === $players) {
            return [];
        }

        $out = [];
        foreach ($this->createQueryBuilder('s')
            ->andWhere('s.player IN (:players)')
            ->andWhere('s.game = :game')
            ->andWhere('s.endedAt IS NOT NULL')
            ->setParameter('players', $players)
            ->setParameter('game', $game)
            ->orderBy('s.endedAt', 'DESC')
            ->getQuery()
            ->getResult() as $session) {
            $out[(int) $session->getPlayer()->getId()][] = $session;
        }

        return $out;
    }

    /** Retention: sessions (and their answers, by cascade) of players inactive in the game since the cutoff. */
    public function purgeInactiveSince(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\SeriousGameSession s WHERE EXISTS (SELECT p.id FROM App\Entity\SeriousGameProgress p WHERE p.player = s.player AND p.game = s.game AND p.lastPlayedAt < :cutoff)'
        )->setParameter('cutoff', $cutoff)->execute();
    }

    /**
     * The openings of a scenario's tickets not ended yet - the current game's, since closing a
     * ticket and starting a new game end them. Every ticket, or one.
     *
     * @return list<SeriousGameSession>
     */
    public function findOpenTicketSessions(User $player, SeriousGame $game, ?string $ticket = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.player = :player')
            ->andWhere('s.game = :game')
            ->andWhere('s.mode = :mode')
            ->andWhere('s.endedAt IS NULL')
            ->setParameter('player', $player)
            ->setParameter('game', $game)
            ->setParameter('mode', SeriousGameSessionMode::Ticket)
            ->orderBy('s.startedAt', 'ASC');
        if (null !== $ticket) {
            $qb->andWhere('s.unitCode = :ticket')->setParameter('ticket', $ticket);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The stars of each ticket at its latest closing: ticket => stars. A ticket closed in the
     * current game was closed by its latest scored opening.
     *
     * @return array<string, int>
     */
    public function latestTicketStars(User $player, SeriousGame $game): array
    {
        /** @var list<array{unitCode: string|null, score: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.unitCode', 's.score')
            ->andWhere('s.player = :player')
            ->andWhere('s.game = :game')
            ->andWhere('s.mode = :mode')
            ->andWhere('s.score IS NOT NULL')
            ->setParameter('player', $player)
            ->setParameter('game', $game)
            ->setParameter('mode', SeriousGameSessionMode::Ticket)
            ->orderBy('s.endedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            if (null !== $row['unitCode'] && null !== $row['score']) {
                $out[$row['unitCode']] = (int) $row['score'];
            }
        }

        return $out;
    }
}
