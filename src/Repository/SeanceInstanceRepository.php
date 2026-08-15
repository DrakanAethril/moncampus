<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\SeanceInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeanceInstance>
 */
class SeanceInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeanceInstance::class);
    }

    /**
     * Every séance of a Program, its own and its séquences', with their slot - what the access
     * condition form offers to hang a "après la séance" on, and what it prints today's date from.
     *
     * @return list<SeanceInstance>
     */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('l', 'q')
            ->leftJoin('s.lessonSession', 'l')
            ->leftJoin('s.sequenceInstance', 'q')
            ->where('s.program = :program')
            ->setParameter('program', $program)
            ->orderBy('q.titre', 'ASC')
            ->addOrderBy('s.ordre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * These séances with their timetable slot already joined - what a seance_passed access
     * condition resolves against, and the one fact that is identical for every student of the
     * program, so it is read once for a whole screen.
     *
     * A séance with no slot comes back all the same, with none: the condition then does not open,
     * and the reason sentence says why rather than naming a date that does not exist.
     *
     * @param list<int> $ids
     *
     * @return array<int, SeanceInstance> keyed by id
     */
    public function findWithSlotByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $seances = $this->createQueryBuilder('s')
            ->addSelect('l')
            ->leftJoin('s.lessonSession', 'l')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($seances as $seance) {
            $byId[(int) $seance->getId()] = $seance;
        }

        return $byId;
    }

    // Powers the lesson log's "pré-remplir" action (App\Controller\LessonLogController) - is this
    // LessonSession backed by a SeanceInstance's frozen content?
    public function findOneByLessonSession(LessonSession $lessonSession): ?SeanceInstance
    {
        return $this->createQueryBuilder('s')
            ->addSelect('p')
            ->leftJoin('s.seancePhaseInstances', 'p')
            ->where('s.lessonSession = :lessonSession')
            ->setParameter('lessonSession', $lessonSession)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
