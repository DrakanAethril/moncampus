<?php

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\SelfAssessment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SelfAssessment> */
class SelfAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SelfAssessment::class);
    }

    public function findOneForStudent(Assignment $assignment, User $student): ?SelfAssessment
    {
        return $this->findOneBy(['assignment' => $assignment, 'student' => $student]);
    }

    /**
     * Toutes les autoévaluations d'un travail, indexées par identifiant d'étudiant - ce que le
     * suivi enseignant (5d) recoupe avec la liste de la classe pour distinguer les rendues des
     * attendues.
     *
     * @return array<int, SelfAssessment>
     */
    public function findByStudentIdForAssignment(Assignment $assignment): array
    {
        $rows = $this->createQueryBuilder('sa')
            ->addSelect('answer')
            ->leftJoin('sa.answers', 'answer')
            ->where('sa.assignment = :assignment')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getResult();

        $byStudentId = [];
        foreach ($rows as $selfAssessment) {
            $byStudentId[$selfAssessment->getStudent()->getId()] = $selfAssessment;
        }

        return $byStudentId;
    }

    /**
     * Combien d'étudiants ont rendu leur estimation, travail par travail - l'avancement de la
     * liste « Travaux » (2b) pour les travaux d'autoévaluation.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, int> identifiant du travail => nombre d'estimations
     */
    public function countByAssignment(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('sa')
            ->select('IDENTITY(sa.assignment) AS assignmentId', 'COUNT(sa.id) AS total')
            ->where('sa.assignment IN (:assignments)')
            ->groupBy('sa.assignment')
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
