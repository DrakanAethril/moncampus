<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameLevelLabel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameLevelLabel>
 */
class GameLevelLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameLevelLabel::class);
    }

    /**
     * Every stored wording, keyed `"<track>|<level>"` - one query for the whole board of screen 3.
     *
     * @return array<string, string>
     */
    public function allByTrackAndLevel(): array
    {
        /** @var list<GameLevelLabel> $rows */
        $rows = $this->findAll();

        $labels = [];
        foreach ($rows as $row) {
            $labels[$row->getTrack()->value.'|'.$row->getLevel()] = $row->getLabel();
        }

        return $labels;
    }
}
