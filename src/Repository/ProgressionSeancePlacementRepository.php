<?php

namespace App\Repository;

use App\Entity\LessonSession;
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
     * The progression placement sitting on this créneau, if any.
     *
     * Backs the lesson log's "pré-remplir" for the créneaux SeanceInstance::$lessonSession cannot
     * name: that link is a unique OneToOne, so a séance duplicated per group or split over two
     * créneaux can only ever point at one of them, while every one of those créneaux teaches the
     * same séance and deserves the same starting content.
     *
     * A retirée séance is skipped - it no longer occupies anything. Ordered so that repeatedly
     * asking the same question gives the same answer when a teacher has manually stacked two
     * séances on one créneau (allowed via 2b).
     */
    public function findOneByLessonSession(LessonSession $session): ?ProgressionSeancePlacement
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.progressionSeance', 'se')
            ->addSelect('se')
            ->where('p.lessonSession = :session')
            ->andWhere('se.removed = false')
            ->setParameter('session', $session)
            ->orderBy('p.partIndex', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
