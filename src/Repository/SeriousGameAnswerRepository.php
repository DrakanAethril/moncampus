<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameAnswer>
 */
class SeriousGameAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameAnswer::class);
    }

    /**
     * The player's last answers in the game - the comets' window -, oldest first.
     *
     * @return list<array{misconception: string|null, at: int}>
     */
    public function lastFor(\App\Entity\User $player, SeriousGame $game, int $limit): array
    {
        /** @var list<array{misconception: string|null, answeredAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.misconception', 'a.answeredAt')
            ->innerJoin('a.session', 's')
            ->andWhere('s.player = :player')
            ->andWhere('s.game = :game')
            ->andWhere('a.answeredAt IS NOT NULL')
            ->setParameter('player', $player)
            ->setParameter('game', $game)
            ->orderBy('a.answeredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_reverse(array_map(static fn (array $row): array => ['misconception' => $row['misconception'], 'at' => $row['answeredAt']->getTimestamp()], $rows));
    }

    /**
     * Every answer of a session, by position.
     *
     * @return array<int, SeriousGameAnswer>
     */
    public function findForSession(\App\Entity\SeriousGameSession $session): array
    {
        $out = [];
        foreach ($this->findBy(['session' => $session], ['position' => 'ASC']) as $answer) {
            $out[$answer->getPosition()] = $answer;
        }

        return $out;
    }

    /** Whether a version of a unit's template has served a question - it must then stay replayable. */
    public function hasServedVersion(SeriousGame $game, string $unit, int $version): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->innerJoin('a.session', 's')
            ->andWhere('s.game = :game')
            ->andWhere('a.unitCode = :unit')
            ->andWhere('a.templateVersion = :version')
            ->setParameter('game', $game)
            ->setParameter('unit', $unit)
            ->setParameter('version', $version)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
