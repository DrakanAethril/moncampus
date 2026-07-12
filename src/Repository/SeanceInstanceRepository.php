<?php

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

    // Standalone (no sequenceInstance) séances for a Program - the "gap-filling" list, shown
    // alongside SequenceInstances on the Program's séquences page.
    /** @return list<SeanceInstance> */
    public function findStandaloneForProgram(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->andWhere('s.sequenceInstance IS NULL')
            ->setParameter('program', $program)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
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
