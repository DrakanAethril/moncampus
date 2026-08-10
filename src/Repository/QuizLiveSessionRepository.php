<?php

declare(strict_types=1);

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

    // Powers the "Concours live" screen a class opens on (Turn 1o's archive, widened): every
    // session, the ones still running first. It replaced a finished-only query when the screen
    // stopped being a mere archive - a teacher arriving here mid-lesson is looking for the session
    // on the projector, not for last month's. The CASE is what puts those first: ordering on the
    // status column itself would order on the enum's storage strings, which mean nothing.
    /** @return list<QuizLiveSession> */
    public function findAllForProgram(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('i')
            ->addSelect('CASE WHEN s.status IN (:terminalStatuses) THEN 1 ELSE 0 END AS HIDDEN isOver')
            ->join('s.quizInstance', 'i')
            ->where('i.program = :program')
            ->setParameter('program', $program)
            ->setParameter('terminalStatuses', [LiveSessionStatus::Finished, LiveSessionStatus::Cancelled])
            ->orderBy('isOver', 'ASC')
            ->addOrderBy('s.finishedAt', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
