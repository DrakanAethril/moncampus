<?php

declare(strict_types=1);

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

    // findForProgram() minus the instances a teacher has deactivated - the *student* reading of the
    // same list (App\Controller\ProgramQuizAttemptController::myQuizzes() and its mobile twin
    // App\Controller\Api\QuizController::mine()). The teacher's own screens deliberately keep
    // findForProgram(): deactivating a quiz hides it from the class, it does not hide its results
    // from the person who launched it - that is the entire reason it is not a deletion.
    /** @return list<QuizInstance> */
    public function findActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.program = :program')
            ->andWhere('i.mode != :live')
            ->andWhere('i.deactivatedAt IS NULL')
            ->setParameter('program', $program)
            ->setParameter('live', QuizMode::Live)
            ->orderBy('i.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // The same list across several classes at once, for App\Controller\QuizTrackingController's
    // Quiz par classes screen - same Live exclusion and same ordering as findForProgram() above.
    // It does NOT filter on the window: the screen counts each state to label its own filter, so
    // it needs the whole set and narrows it in PHP through App\Enum\QuizInstanceState, which is
    // also what stamps the badge on every row. One rule, one place - a WHERE clause repeating the
    // enum's comparison is exactly how a list and its badges come to disagree.
    //
    // @param list<Program> $programs
    //
    /** @return list<QuizInstance> */
    public function findForPrograms(array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->addSelect('p')
            ->join('i.program', 'p')
            ->where('i.program IN (:programs)')
            ->andWhere('i.mode != :live')
            ->setParameter('programs', $programs)
            ->setParameter('live', QuizMode::Live)
            ->orderBy('i.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Feeds App\Twig\StructureNavigationExtension's per-request "does this Program have a Quiz
    // nav entry" cache - a single DISTINCT query covering every Program at once (not one COUNT
    // per Program row) since the nav renders on every authenticated page for every visible
    // Program.
    /**
     * The same set findActiveForProgram() would return, clause for clause: a Live session is played
     * from its own screen and a deactivated instance is gone from the list, so a class holding only
     * those has nothing to show and must not carry the entry - it would open on an empty table.
     *
     * @return list<int>
     */
    public function findProgramIdsWithInstances(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT IDENTITY(i.program) AS programId')
            ->where('i.mode != :live')
            ->andWhere('i.deactivatedAt IS NULL')
            ->setParameter('live', QuizMode::Live)
            ->getQuery()
            ->getScalarResult();

        return array_map(intval(...), array_column($rows, 'programId'));
    }
}
