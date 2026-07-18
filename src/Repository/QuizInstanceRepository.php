<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Enum\QuizMode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizInstance>
 */
class QuizInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizInstance::class);
    }

    // Powers App\Controller\ProgramQuizController::list() (teacher's "Par étudiant/Par question"
    // results) and App\Controller\ProgramQuizAttemptController::myQuizzes() (student's
    // entraînement/évaluation hub) - most recently launched first. Deliberately excludes
    // QuizMode::Live: those instances have no QuizAttempt rows at all (see
    // App\Service\QuizLiveSessionService's class docblock), so surfacing them here would either
    // show a permanently-empty results page (teacher side) or let a student "s'entraîner" async on
    // what's meant to be a synchronized live game (student side) - they're reached exclusively via
    // App\Repository\QuizLiveSessionRepository instead (the "Concours en cours" banner / Sessions
    // live archive).
    /** @return list<QuizInstance> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.program = :program')
            ->andWhere('i.mode != :live')
            ->setParameter('program', $program)
            ->setParameter('live', QuizMode::Live)
            ->orderBy('i.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Feeds App\Twig\StructureNavigationExtension's per-request "does this Program have a Quiz
    // nav entry" cache - a single DISTINCT query covering every Program at once (not one COUNT
    // per Program row) since the nav renders on every authenticated page for every visible
    // Program.
    /** @return list<int> */
    public function findProgramIdsWithInstances(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT IDENTITY(i.program) AS programId')
            ->getQuery()
            ->getScalarResult();

        return array_map(intval(...), array_column($rows, 'programId'));
    }
}
