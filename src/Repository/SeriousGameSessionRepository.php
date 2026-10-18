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
}
