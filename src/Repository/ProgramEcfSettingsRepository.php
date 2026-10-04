<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\ProgramEcfSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramEcfSettings>
 */
class ProgramEcfSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramEcfSettings::class);
    }

    public function findOneByProgram(Program $program): ?ProgramEcfSettings
    {
        return $this->findOneBy(['program' => $program]);
    }
}
