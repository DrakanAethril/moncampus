<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameTeamSet;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameTeamSet>
 */
class GameTeamSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameTeamSet::class);
    }

    public function findForProgram(Program $program): ?GameTeamSet
    {
        return $this->findOneBy(['program' => $program]);
    }
}
