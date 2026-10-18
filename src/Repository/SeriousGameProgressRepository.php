<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameProgress;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameProgress>
 */
class SeriousGameProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameProgress::class);
    }

    public function findOneFor(User $player, SeriousGame $game): ?SeriousGameProgress
    {
        return $this->findOneBy(['player' => $player, 'game' => $game]);
    }

    /** @return array<int, SeriousGameProgress> game id => progress, for one player */
    public function findForPlayer(User $player): array
    {
        $out = [];
        foreach ($this->findBy(['player' => $player]) as $progress) {
            $out[(int) $progress->getGame()->getId()] = $progress;
        }

        return $out;
    }

    /**
     * @param list<User> $players
     *
     * @return array<int, SeriousGameProgress> player id => progress
     */
    public function findForPlayers(array $players, SeriousGame $game): array
    {
        if ([] === $players) {
            return [];
        }

        $out = [];
        foreach ($this->findBy(['player' => $players, 'game' => $game]) as $progress) {
            $out[(int) $progress->getPlayer()->getId()] = $progress;
        }

        return $out;
    }
}
