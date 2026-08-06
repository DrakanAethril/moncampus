<?php

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
     * Les identifiants des travaux qu'un étudiant a déclarés faits, pour trancher en une requête
     * le « à faire » du « fait » sur une liste entière plutôt qu'un travail à la fois.
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

    /** Au moins un étudiant a-t-il déclaré ce travail fait ? */
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
     * Combien d'étudiants ont déclaré chaque travail fait, en une requête plutôt qu'une par travail
     * - le suivi de lecture s'affiche sur toute une séance à la fois.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, int> identifiant du travail => nombre de déclarations
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
}
