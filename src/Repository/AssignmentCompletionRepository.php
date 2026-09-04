<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\AssignmentCompletion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssignmentCompletion>
 */
class AssignmentCompletionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssignmentCompletion::class);
    }

    public function findOneFor(Assignment $assignment, User $student): ?AssignmentCompletion
    {
        return $this->findOneBy(['assignment' => $assignment, 'student' => $student]);
    }

    /**
     * The identifiers of the assignments a student has declared done, to tell « to do » from « done »
     * over a whole list in one query rather than one assignment at a time.
     *
     * @param list<Assignment> $assignments
     *
     * @return list<int>
     */
    public function findDoneAssignmentIds(array $assignments, User $student): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.assignment) AS assignmentId')
            ->where('c.assignment IN (:assignments)')
            ->andWhere('c.student = :student')
            ->setParameter('assignments', $assignments)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['assignmentId'], $rows);
    }

    /**
     * When a student declared each of these assignments done - the same reading as
     * findDoneAssignmentIds(), plus the date, which the "Derniers travaux" column prints.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, \DateTimeImmutable> assignment id => declaration date
     */
    public function findDoneDates(array $assignments, User $student): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.assignment) AS assignmentId', 'c.doneAt AS doneAt')
            ->where('c.assignment IN (:assignments)')
            ->andWhere('c.student = :student')
            ->setParameter('assignments', $assignments)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['assignmentId']] = $row['doneAt'];
        }

        return $dates;
    }

    /**
     * Who declared this one assignment done, and when - the teacher's follow-up reads a whole class
     * at once, where findDoneDates() reads one student across a whole list.
     *
     * @return array<int, \DateTimeImmutable> student id => declaration date
     */
    public function findDoneDatesByStudentIdForAssignment(Assignment $assignment): array
    {
        /** @var list<array{studentId: int|string, doneAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.student) AS studentId', 'c.doneAt AS doneAt')
            ->where('c.assignment = :assignment')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['studentId']] = $row['doneAt'];
        }

        return $dates;
    }

    /** Has at least one student declared this assignment done? */
    public function hasAnyForAssignment(Assignment $assignment): bool
    {
        return 0 < (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.assignment = :assignment')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many students declared each assignment done, in one query rather than one per assignment -
     * the read tracking is displayed for a whole séance at a time.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, int> assignment identifier => number of declarations
     */
    public function countByAssignment(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.assignment) AS assignmentId', 'COUNT(c.id) AS total')
            ->where('c.assignment IN (:assignments)')
            ->groupBy('c.assignment')
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
     * The same reading as findDoneAssignmentIds(), from ids alone - an access condition names an
     * assignment by id, and loading the row to pass it back would be work done for nothing.
     *
     * @param list<int> $assignmentIds
     *
     * @return list<int>
     */
    public function findDoneAssignmentIdsForStudent(array $assignmentIds, User $student): array
    {
        if ([] === $assignmentIds) {
            return [];
        }

        /** @var list<array{assignmentId: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.assignment) AS assignmentId')
            ->where('c.assignment IN (:assignments)')
            ->andWhere('c.student = :student')
            ->setParameter('assignments', $assignmentIds)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['assignmentId'], $rows);
    }
}
