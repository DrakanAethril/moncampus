<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\AssignmentView;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssignmentView>
 */
class AssignmentViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssignmentView::class);
    }

    public function findOneFor(Assignment $assignment, User $student): ?AssignmentView
    {
        return $this->findOneBy(['assignment' => $assignment, 'student' => $student]);
    }

    /**
     * How many distinct students opened each assignment, in one query rather than one per assignment
     * - the tracking is displayed for a whole séance at a time.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, int> assignment identifier => number of students who opened it
     */
    public function countByAssignment(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.assignment) AS assignmentId', 'COUNT(v.id) AS total')
            ->where('v.assignment IN (:assignments)')
            ->groupBy('v.assignment')
            ->setParameter('assignments', $assignments)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['assignmentId']] = (int) $row['total'];
        }

        return $counts;
    }
}
