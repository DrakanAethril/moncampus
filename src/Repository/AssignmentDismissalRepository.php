<?php

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\AssignmentDismissal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssignmentDismissal>
 */
class AssignmentDismissalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssignmentDismissal::class);
    }

    public function findOneFor(Assignment $assignment, User $student): ?AssignmentDismissal
    {
        return $this->findOneBy(['assignment' => $assignment, 'student' => $student]);
    }

    /**
     * The assignments a student has set aside, settled in one query for a whole list rather than
     * one assignment at a time - same shape as AssignmentCompletionRepository::findDoneAssignmentIds().
     *
     * @param list<Assignment> $assignments
     *
     * @return list<int>
     */
    public function findDismissedAssignmentIds(array $assignments, User $student): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.assignment) AS assignmentId')
            ->where('d.assignment IN (:assignments)')
            ->andWhere('d.student = :student')
            ->setParameter('assignments', $assignments)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['assignmentId'], $rows);
    }
}
