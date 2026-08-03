<?php

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
     * Combien d'étudiants distincts ont ouvert chaque travail, en une requête plutôt qu'une par
     * travail - le suivi s'affiche sur toute une séance à la fois.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, int> identifiant du travail => nombre d'étudiants l'ayant ouvert
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
