<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameProgramSettings;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameProgramSettings>
 */
class GameProgramSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameProgramSettings::class);
    }

    public function findForProgram(Program $program): ?GameProgramSettings
    {
        return $this->findOneBy(['program' => $program]);
    }

    public function countEnabledPrograms(): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(p.id) FROM App\Entity\Program p WHERE p.gameEnabled = true')
            ->getSingleScalarResult();
    }
}
