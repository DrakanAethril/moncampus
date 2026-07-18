<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\QuizLiveSession;
use App\Enum\LiveSessionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizLiveSession>
 */
class QuizLiveSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizLiveSession::class);
    }

    // Powers the code-less "Concours en cours" banner (program/quiz_mine.html.twig) - at most one
    // non-terminal session per program in practice (a teacher wouldn't run two at once), but this
    // returns the most recently created if somehow more than one is active.
    public function findActiveForProgram(Program $program): ?QuizLiveSession
    {
        return $this->createQueryBuilder('s')
            ->join('s.quizInstance', 'i')
            ->where('i.program = :program')
            ->andWhere('s.status IN (:activeStatuses)')
            ->setParameter('program', $program)
            ->setParameter('activeStatuses', [
                LiveSessionStatus::Lobby,
                LiveSessionStatus::Countdown,
                LiveSessionStatus::Question,
                LiveSessionStatus::Reveal,
            ])
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Powers the "Sessions live" results archive (Turn 1o) - most recently finished first.
    /** @return list<QuizLiveSession> */
    public function findFinishedForProgram(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.quizInstance', 'i')
            ->where('i.program = :program')
            ->andWhere('s.status IN (:terminalStatuses)')
            ->setParameter('program', $program)
            ->setParameter('terminalStatuses', [LiveSessionStatus::Finished, LiveSessionStatus::Cancelled])
            ->orderBy('s.finishedAt', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
