<?php

namespace App\Repository;

use App\Entity\LessonSession;
use App\Entity\Program;
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
     * The SeanceInstances of this Program that a progression has actually committed to a créneau -
     * the "x / y programmées" column of the Program-side séquences list.
     *
     * Derived from the placements rather than read off SeanceInstance::$lessonSession, because that
     * link is a unique OneToOne written at validation time: it can only ever name one créneau, and
     * it stays whatever it was until someone validates again. Counting from the placements means a
     * séance duplicated per group counts once (not zero), and that a progression validated before
     * that was true reports correctly without anyone re-validating it.
     *
     * @return array<int, true> SeanceInstance id => committed
     */
    public function findScheduledSeanceInstanceIdsForProgram(Program $program): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(se.seanceInstance) AS instanceId')
            ->innerJoin('p.progressionSeance', 'se')
            ->innerJoin('se.seanceInstance', 'si')
            ->where('si.program = :program')
            ->andWhere('se.removed = false')
            ->andWhere('p.confirmed = true')
            ->andWhere('p.lessonSession IS NOT NULL')
            ->setParameter('program', $program)
            ->groupBy('instanceId')
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['instanceId']] = true;
        }

        return $ids;
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
