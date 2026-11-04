<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentPlayer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizTournamentPlayer>
 */
class QuizTournamentPlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournamentPlayer::class);
    }

    public function findOneFor(QuizTournament $tournament, User $user): ?QuizTournamentPlayer
    {
        return $this->findOneBy(['tournament' => $tournament, 'user' => $user]);
    }

    /**
     * The player's line, created (persisted, not flushed) the first time they take part - a
     * challenge sent or accepted, a duel started (§9). Callers hold a lock that keeps two first
     * gestures of one person from both creating it.
     */
    public function findOrCreate(QuizTournament $tournament, User $user): QuizTournamentPlayer
    {
        $player = $this->findOneFor($tournament, $user);
        if (null === $player) {
            $player = new QuizTournamentPlayer($tournament, $user);
            $this->getEntityManager()->persist($player);
        }

        return $player;
    }

    /**
     * Every player of the tournament, keyed by user id, users loaded.
     *
     * @return array<int, QuizTournamentPlayer>
     */
    public function findByTournamentIndexedByUser(QuizTournament $tournament): array
    {
        /** @var list<QuizTournamentPlayer> $players */
        $players = $this->createQueryBuilder('p')
            ->addSelect('u')
            ->innerJoin('p.user', 'u')
            ->where('p.tournament = :tournament')
            ->setParameter('tournament', $tournament)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($players as $player) {
            $indexed[(int) $player->getUser()->getId()] = $player;
        }

        return $indexed;
    }

    /**
     * Both players of a duel, locked for the rest of the transaction - the rating update of §6.1
     * moves both lines at once. Locked in id order so two concurrent duels cannot deadlock. Must run
     * inside a transaction.
     *
     * @return list<QuizTournamentPlayer>
     */
    public function lockPlayers(QuizTournamentPlayer ...$players): array
    {
        usort($players, static fn (QuizTournamentPlayer $a, QuizTournamentPlayer $b): int => (int) $a->getId() <=> (int) $b->getId());
        foreach ($players as $player) {
            $this->getEntityManager()->lock($player, LockMode::PESSIMISTIC_WRITE);
            $this->getEntityManager()->refresh($player);
        }

        return $players;
    }
}
