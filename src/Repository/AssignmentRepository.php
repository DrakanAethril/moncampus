<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Enum\AssignmentNature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assignment>
 */
class AssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    /**
     * A travail by id, deleted ones excepted - the only correct way to look one up.
     *
     * `find()` is not: a soft deletion writes a date and leaves the row where it is, so the inherited
     * finder keeps answering a travail nobody may open any more. Every screen and every API endpoint
     * reaching for an assignment by its id goes through this.
     */
    public function findLive(int $id): ?Assignment
    {
        /** @var ?Assignment $assignment */
        $assignment = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $assignment;
    }

    /**
     * The same reading over a set of ids, for a screen naming several travaux at once.
     *
     * @param list<int> $ids
     *
     * @return list<Assignment>
     */
    public function findLiveByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Assignment> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.program = :program')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('program', $program)
            ->orderBy('a.dueDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The teacher's « Travaux » list (design_handoff_creation_travail 2b): their assignments across
     * all their classes at once, from the nearest to the furthest - an overdue assignment reads at
     * the top because it is the one whose submissions are coming in, not because it is old.
     *
     * $creator is required rather than optional, and there is no "everybody" reading: a travail
     * belongs to whoever gave it, so this list is always somebody's own - staff and administrators
     * included. The web screen and the mobile 4d screen therefore answer the same thing.
     *
     * @param list<Program> $programs
     *
     * @return list<Assignment>
     */
    public function findForPrograms(array $programs, User $creator): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o', 'p', 't', 'e')
            ->leftJoin('a.options', 'o')
            ->leftJoin('a.program', 'p')
            ->leftJoin('a.topic', 't')
            ->leftJoin('a.expectedProductions', 'e')
            ->where('a.program IN (:programs)')
            ->andWhere('a.createdBy = :creator')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('programs', $programs)
            ->setParameter('creator', $creator)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // The assignments given from a séance's cahier de texte (mockup 2a), all parts together - the
    // controller then sorts them by part.
    /** @return list<Assignment> */
    public function findForLessonSession(LessonSession $session): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.lessonSession = :session')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('session', $session)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The travail carrying a quiz already launched, if there is one - what « Convertir en note » on
     * the quiz's results screen needs to know: the conversion is a gesture of the travail, so the
     * screen either sends the teacher to it or offers to create it.
     *
     * Nothing forbids two travaux from naming the same quiz, so the choice is stated rather than
     * left to the database: the one already converted first - it is the one the carnet reads - then
     * the most recent. A HIDDEN alias carries the ranking, an ORDER BY on the association itself
     * sorting on the foreign key.
     */
    public function findCarryingQuizInstance(QuizInstance $instance): ?Assignment
    {
        /** @var ?Assignment $assignment */
        $assignment = $this->createQueryBuilder('a')
            ->addSelect('CASE WHEN a.gradebookEvaluation IS NULL THEN 1 ELSE 0 END AS HIDDEN converted')
            ->where('a.quizInstance = :instance')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('instance', $instance)
            ->orderBy('converted', 'ASC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $assignment;
    }

    /**
     * The same list over a whole set of créneaux - the period screen shows every séance of the week
     * at once, and asking séance by séance would be one query per row.
     *
     * @param list<LessonSession> $sessions
     *
     * @return list<Assignment>
     */
    public function findForLessonSessions(array $sessions): array
    {
        if ([] === $sessions) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.lessonSession IN (:sessions)')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('sessions', $sessions)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All of a student's work, overdue included - what the « Travail à réaliser » page (4a) shows,
     * unlike the dashboard card, which sticks to what is coming.
     * Membership of the audience is still filtered by the caller through AssignmentAudienceResolver.
     *
     * @param list<Program> $programs
     *
     * @return list<Assignment>
     */
    public function findVisibleForPrograms(array $programs, \DateTimeImmutable $now): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o', 'l')
            ->leftJoin('a.options', 'o')
            ->leftJoin('a.lessonSession', 'l')
            ->where('a.program IN (:programs)')
            ->andWhere('a.deletedAt IS NULL')
            // An assignment given from a séance only exists for the student once published; the
            // assignments of the historical screen were published by the migration, so they all pass.
            ->andWhere('a.visibleAt IS NOT NULL AND a.visibleAt <= :now')
            ->setParameter('programs', $programs)
            ->setParameter('now', $now)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The published self-assessment works bearing on these evaluations - what
     * App\Service\SelfAssessmentGradeGate needs to know whether a grade is still being held back
     * from a student. Unpublished works hold nothing back: the student cannot even see them.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return list<Assignment>
     */
    public function findPublishedSelfAssessmentsForEvaluations(array $evaluations, \DateTimeImmutable $now): array
    {
        if ([] === $evaluations) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.evaluation IN (:evaluations)')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.nature = :nature')
            ->andWhere('a.visibleAt IS NOT NULL AND a.visibleAt <= :now')
            ->setParameter('evaluations', $evaluations)
            ->setParameter('nature', AssignmentNature::SelfAssessment)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
