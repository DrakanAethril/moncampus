<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\AssignmentDismissal;
use App\Entity\AssignmentExpectedProduction;
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

    public function findOneFor(Assignment $assignment, User $student, ?AssignmentExpectedProduction $expectedProduction = null): ?AssignmentDismissal
    {
        return $this->findOneBy([
            'assignment' => $assignment,
            'student' => $student,
            'expectedProduction' => $expectedProduction,
        ]);
    }

    /**
     * What a student has set aside, settled in one query for a whole list rather than one
     * assignment at a time - same shape as AssignmentCompletionRepository::findDoneAssignmentIds().
     *
     * Keyed by assignment, each holding the expected productions set aside within it, and null
     * when it is the assignment as a whole that was - the two live side by side, an assignment
     * spelling out productions being dismissed one deadline at a time.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, list<int|null>>
     */
    public function findDismissedProductionIds(array $assignments, User $student): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.assignment) AS assignmentId', 'IDENTITY(d.expectedProduction) AS productionId')
            ->where('d.assignment IN (:assignments)')
            ->andWhere('d.student = :student')
            ->setParameter('assignments', $assignments)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $dismissed = [];
        foreach ($rows as $row) {
            $dismissed[(int) $row['assignmentId']][] = null === $row['productionId'] ? null : (int) $row['productionId'];
        }

        return $dismissed;
    }
}
