<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameComet;
use App\Entity\User;
use App\Enum\SeriousGameCometState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameComet>
 */
class SeriousGameCometRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameComet::class);
    }

    /** @return list<SeriousGameComet> the comets in view, the oldest first */
    public function findInView(User $player, SeriousGame $game): array
    {
        return $this->findBy(['player' => $player, 'game' => $game, 'state' => SeriousGameCometState::InView], ['appearedAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneInView(User $player, SeriousGame $game, string $misconception): ?SeriousGameComet
    {
        return $this->findOneBy(['player' => $player, 'game' => $game, 'misconception' => $misconception, 'state' => SeriousGameCometState::InView]);
    }

    /** @return list<SeriousGameComet> every comet of the player, the latest first */
    public function findForPlayer(User $player, SeriousGame $game): array
    {
        return $this->findBy(['player' => $player, 'game' => $game], ['appearedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return array<string, int> error => timestamp its last comet was solved */
    public function solvedAt(User $player, SeriousGame $game): array
    {
        $out = [];
        foreach ($this->findBy(['player' => $player, 'game' => $game, 'state' => SeriousGameCometState::Solved]) as $comet) {
            $at = $comet->getSolvedAt()?->getTimestamp() ?? 0;
            $out[$comet->getMisconception()] = max($out[$comet->getMisconception()] ?? 0, $at);
        }

        return $out;
    }

    /**
     * @param list<User> $players
     *
     * @return list<SeriousGameComet>
     */
    public function findForPlayers(array $players, SeriousGame $game): array
    {
        return [] === $players ? [] : $this->findBy(['player' => $players, 'game' => $game]);
    }

    /** Retention: comets of players inactive in the game since the cutoff. */
    public function purgeInactiveSince(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\SeriousGameComet c WHERE EXISTS (SELECT p.id FROM App\Entity\SeriousGameProgress p WHERE p.player = c.player AND p.game = c.game AND p.lastPlayedAt < :cutoff)'
        )->setParameter('cutoff', $cutoff)->execute();
    }
}
