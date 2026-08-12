<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\AssignmentExpectedProduction;
use App\Entity\AssignmentSubmission;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssignmentSubmission>
 */
class AssignmentSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssignmentSubmission::class);
    }

    /**
     * The submission answering one precise expected production - or, with $production left null,
     * the assignment-wide one an assignment without a detailed breakdown produces. The two are
     * never interchangeable: a null production is a value here, not a wildcard.
     */
    public function findOneForAssignmentAndStudent(Assignment $assignment, User $student, ?AssignmentExpectedProduction $production = null): ?AssignmentSubmission
    {
        $builder = $this->createQueryBuilder('s')
            ->addSelect('f')
            ->leftJoin('s.files', 'f')
            ->where('s.assignment = :assignment')
            ->andWhere('s.student = :student')
            ->setParameter('assignment', $assignment)
            ->setParameter('student', $student);

        if (null === $production) {
            $builder->andWhere('s.expectedProduction IS NULL');
        } else {
            $builder->andWhere('s.expectedProduction = :production')->setParameter('production', $production);
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    /**
     * Everything one student has handed in on one assignment, oldest first - one row per expected
     * production once the assignment spells them out, a single row otherwise.
     *
     * @return list<AssignmentSubmission>
     */
    public function findForAssignmentAndStudent(Assignment $assignment, User $student): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('f')
            ->leftJoin('s.files', 'f')
            ->where('s.assignment = :assignment')
            ->andWhere('s.student = :student')
            ->setParameter('assignment', $assignment)
            ->setParameter('student', $student)
            ->orderBy('s.submittedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Who handed in what on this assignment, for the teacher-facing follow-up screens. A list per
     * student rather than a single submission, oldest first: an assignment asking for several
     * productions gets one submission per production, and the review screens must see them all.
     *
     * @return array<int, list<AssignmentSubmission>> student id => their submissions
     */
    public function findAllByStudentIdForAssignment(Assignment $assignment): array
    {
        $submissions = $this->createQueryBuilder('s')
            ->addSelect('f')
            ->leftJoin('s.files', 'f')
            ->where('s.assignment = :assignment')
            ->setParameter('assignment', $assignment)
            ->orderBy('s.submittedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byStudentId = [];
        foreach ($submissions as $submission) {
            $byStudentId[$submission->getStudent()->getId()][] = $submission;
        }

        return $byStudentId;
    }

    /**
     * Everything one student has handed in across a whole list of assignments, in one query - the
     * student's own "Travail à faire" screen weighs every assignment against its deposits at once,
     * and would otherwise run a query per row.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, list<AssignmentSubmission>> assignment id => their submissions, oldest first
     */
    public function findByAssignmentIdForStudent(array $assignments, User $student): array
    {
        if ([] === $assignments) {
            return [];
        }

        $submissions = $this->createQueryBuilder('s')
            ->addSelect('f')
            ->leftJoin('s.files', 'f')
            ->where('s.assignment IN (:assignments)')
            ->andWhere('s.student = :student')
            ->setParameter('assignments', $assignments)
            ->setParameter('student', $student)
            ->orderBy('s.submittedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byAssignmentId = [];
        foreach ($submissions as $submission) {
            $byAssignmentId[$submission->getAssignment()->getId()][] = $submission;
        }

        return $byAssignmentId;
    }

    /**
     * Combien d'étudiants ont déposé, travail par travail - l'avancement de la liste « Travaux »
     * (2b), qui se lit sur toutes les classes de l'enseignant d'un coup.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, int> identifiant du travail => nombre de déposants
     */
    public function countByAssignment(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            // Students, not rows: an assignment asking for several productions holds one submission
            // per production, and three deposits by one student is still one student having handed in.
            ->select('IDENTITY(s.assignment) AS assignmentId', 'COUNT(DISTINCT s.student) AS total')
            ->where('s.assignment IN (:assignments)')
            ->groupBy('s.assignment')
            ->setParameter('assignments', $assignments)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['assignmentId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Les identifiants des étudiants ayant déposé, travail par travail - il faut savoir QUI a
     * déposé, et non combien, pour compter les groupes ayant rendu (un seul membre dépose pour son
     * groupe).
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, list<int>> identifiant du travail => identifiants des déposants
     */
    public function findSubmitterIdsByAssignment(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT IDENTITY(s.assignment) AS assignmentId', 'IDENTITY(s.student) AS studentId')
            ->where('s.assignment IN (:assignments)')
            ->setParameter('assignments', $assignments)
            ->getQuery()
            ->getResult();

        $byAssignment = [];
        foreach ($rows as $row) {
            $byAssignment[(int) $row['assignmentId']][] = (int) $row['studentId'];
        }

        return $byAssignment;
    }

    /**
     * Y a-t-il au moins un dépôt sur ce travail ? Le supprimer emporterait les fichiers déposés :
     * l'import depuis la bibliothèque s'y refuse, et laisse ce geste à l'enseignant.
     */
    public function hasAnyForAssignment(Assignment $assignment): bool
    {
        return 0 < (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.assignment = :assignment')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Which of these assignments the student has handed something in for - half of what an
     * assignment_done access condition reads, the other half being the completions they declared.
     *
     * Takes ids rather than entities: a condition names an assignment by id and nothing else of it
     * is needed, so loading the row would be work done for nothing.
     *
     * @param list<int> $assignmentIds
     *
     * @return list<int>
     */
    public function findSubmittedAssignmentIdsForStudent(array $assignmentIds, User $student): array
    {
        if ([] === $assignmentIds) {
            return [];
        }

        /** @var list<array{assignmentId: int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT IDENTITY(s.assignment) AS assignmentId')
            ->where('s.assignment IN (:assignments)')
            ->andWhere('s.student = :student')
            ->setParameter('assignments', $assignmentIds)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['assignmentId'], $rows);
    }
}
