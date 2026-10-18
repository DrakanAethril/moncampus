<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameRoleSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameRoleSetting>
 */
class SeriousGameRoleSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameRoleSetting::class);
    }

    /**
     * Every game's line of the matrix, read in one query: game id => role => lit.
     *
     * @return array<int, array<string, bool>>
     */
    public function matrix(): array
    {
        /** @var list<array{gameId: int|string, role: string, enabled: bool|int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.game) AS gameId', 's.role', 's.enabled')
            ->getQuery()
            ->getArrayResult();

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[(int) $row['gameId']][$row['role']] = (bool) $row['enabled'];
        }

        return $matrix;
    }

    /** @return list<SeriousGameRoleSetting> */
    public function findForGame(SeriousGame $game): array
    {
        return $this->findBy(['game' => $game]);
    }
}
