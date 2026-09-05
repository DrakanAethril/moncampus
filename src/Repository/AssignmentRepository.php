<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Entity\LessonSession;
use App\Entity\Program;
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

    /** @return list<Assignment> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.program = :program')
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
     * $creator restricts to the assignments given by the teacher themselves; null (staff) returns
     * those of the whole team on the classes targeted.
     *
     * @param list<Program> $programs
     *
     * @return list<Assignment>
     */
    public function findForPrograms(array $programs, ?User $creator = null): array
    {
        if ([] === $programs) {
            return [];
        }

        $builder = $this->createQueryBuilder('a')
            ->addSelect('o', 'p', 't', 'e')
            ->leftJoin('a.options', 'o')
            ->leftJoin('a.program', 'p')
            ->leftJoin('a.topic', 't')
            ->leftJoin('a.expectedProductions', 'e')
            ->where('a.program IN (:programs)')
            ->setParameter('programs', $programs)
            ->orderBy('a.dueDate', 'ASC');

        if (null !== $creator) {
            $builder->andWhere('a.createdBy = :creator')->setParameter('creator', $creator);
        }

        return $builder->getQuery()->getResult();
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
            ->setParameter('session', $session)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
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
            ->andWhere('a.nature = :nature')
            ->andWhere('a.visibleAt IS NOT NULL AND a.visibleAt <= :now')
            ->setParameter('evaluations', $evaluations)
            ->setParameter('nature', AssignmentNature::SelfAssessment)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
