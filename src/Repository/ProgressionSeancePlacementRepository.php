<?php

namespace App\Repository;

use App\Entity\Progression;
use App\Entity\ProgressionSeancePlacement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgressionSeancePlacement>
 */
class ProgressionSeancePlacementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgressionSeancePlacement::class);
    }

    /**
     * Which créneaux of this progression's timetable are already taken, and by whom - the "une
     * seule séance par créneau en automatique" check of §4.2, and what greys nothing out but does
     * label a slot as busy in the 2b picker.
     *
     * @return array<int, int> LessonSession id => number of séances placed on it
     */
    public function countPlacementsPerSessionForProgression(Progression $progression): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.lessonSession) AS sessionId', 'COUNT(p.id) AS total')
            ->innerJoin('p.progressionSeance', 'se')
            ->innerJoin('se.progressionSequence', 'sq')
            ->where('sq.progression = :progression')
            ->andWhere('p.lessonSession IS NOT NULL')
            ->andWhere('se.removed = false')
            ->setParameter('progression', $progression)
            ->groupBy('sessionId')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['sessionId']] = (int) $row['total'];
        }

        return $counts;
    }
}
