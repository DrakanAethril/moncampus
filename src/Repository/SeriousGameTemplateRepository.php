<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameTemplate;
use App\Enum\SeriousGameTemplateStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameTemplate>
 */
class SeriousGameTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameTemplate::class);
    }

    /** A unit's current template: its highest validated version. */
    public function findCurrent(SeriousGame $game, string $unit): ?SeriousGameTemplate
    {
        return $this->findOneBy(['game' => $game, 'unitCode' => $unit, 'status' => SeriousGameTemplateStatus::Validated], ['version' => 'DESC']);
    }

    public function findVersion(SeriousGame $game, string $unit, int $version): ?SeriousGameTemplate
    {
        return $this->findOneBy(['game' => $game, 'unitCode' => $unit, 'version' => $version]);
    }

    /**
     * Every unit's current template, read in one query: unit => template.
     *
     * @return array<string, SeriousGameTemplate>
     */
    public function findCurrentByUnit(SeriousGame $game): array
    {
        $current = [];
        foreach ($this->findBy(['game' => $game, 'status' => SeriousGameTemplateStatus::Validated], ['version' => 'ASC']) as $template) {
            $current[$template->getUnitCode()] = $template;
        }

        return $current;
    }

    /** @return list<SeriousGameTemplate> every version of a unit, the latest first */
    public function findVersions(SeriousGame $game, string $unit): array
    {
        return $this->findBy(['game' => $game, 'unitCode' => $unit], ['version' => 'DESC']);
    }

    /** @return list<SeriousGameTemplate> every version of every unit of a game */
    public function findForGame(SeriousGame $game): array
    {
        return $this->findBy(['game' => $game], ['unitCode' => 'ASC', 'version' => 'DESC']);
    }

    public function nextVersion(SeriousGame $game, string $unit): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.version)')
            ->andWhere('t.game = :game')
            ->andWhere('t.unitCode = :unit')
            ->setParameter('game', $game)
            ->setParameter('unit', $unit)
            ->getQuery()
            ->getSingleScalarResult();

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }
}
