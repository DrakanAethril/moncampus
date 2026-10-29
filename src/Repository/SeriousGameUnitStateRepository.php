<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameUnitState;
use App\Entity\User;
use App\Enum\SeriousGameMechanic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameUnitState>
 */
class SeriousGameUnitStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameUnitState::class);
    }

    /** @return array<string, SeriousGameUnitState> unit code => state */
    public function findForPlayer(User $player, SeriousGame $game): array
    {
        $out = [];
        foreach ($this->findBy(['player' => $player, 'game' => $game]) as $state) {
            $out[$state->getUnitCode()] = $state;
        }

        return $out;
    }

    public function findOneFor(User $player, SeriousGame $game, string $unit): ?SeriousGameUnitState
    {
        return $this->findOneBy(['player' => $player, 'game' => $game, 'unitCode' => $unit]);
    }

    /**
     * The states of several players in one game, read in one query: player id => unit => state.
     *
     * @param list<User> $players
     *
     * @return array<int, array<string, SeriousGameUnitState>>
     */
    public function findForPlayers(array $players, SeriousGame $game): array
    {
        if ([] === $players) {
            return [];
        }

        $out = [];
        foreach ($this->findBy(['player' => $players, 'game' => $game]) as $state) {
            $out[(int) $state->getPlayer()->getId()][$state->getUnitCode()] = $state;
        }

        return $out;
    }

    /**
     * How many units a player lit in each game: game id => count. A unit of the series is lit at
     * `$litAt`; a ticket of a scenario once it was closed, whatever its stars.
     *
     * @return array<int, int> game id => lit units, for one player
     */
    public function litCountsFor(User $player, int $litAt = 6): array
    {
        /** @var list<array{gameId: int|string, lit: int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.game) AS gameId', 'COUNT(s.id) AS lit')
            ->innerJoin('s.game', 'g')
            ->andWhere('s.player = :player')
            ->andWhere('(g.mechanic = :scenario AND s.best IS NOT NULL) OR s.best >= :litAt')
            ->setParameter('player', $player)
            ->setParameter('litAt', $litAt)
            ->setParameter('scenario', SeriousGameMechanic::Scenario)
            ->groupBy('s.game')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['gameId']] = (int) $row['lit'];
        }

        return $out;
    }

    /**
     * The tickets on a player's board in a scenario, in the order they came onto it.
     *
     * @return list<SeriousGameUnitState>
     */
    public function findBoard(User $player, SeriousGame $game): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.player = :player')
            ->andWhere('s.game = :game')
            ->andWhere('s.status IS NOT NULL')
            ->setParameter('player', $player)
            ->setParameter('game', $game)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
