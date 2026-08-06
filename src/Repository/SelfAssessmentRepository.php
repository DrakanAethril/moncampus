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
     * When a student validated their estimate, assignment by assignment - the proof of completion
     * a self-assessment carries, settled for a whole list in one query. Drafts are left out: they
     * are picked up again, they do not finish anything.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<int, \DateTimeImmutable> assignment id => validation date
     */
    public function findValidationDatesForStudent(array $assignments, User $student): array
    {
        if ([] === $assignments) {
            return [];
        }

        $rows = $this->createQueryBuilder('sa')
            ->select('IDENTITY(sa.assignment) AS assignmentId', 'sa.validatedAt AS validatedAt')
            ->where('sa.assignment IN (:assignments)')
            ->andWhere('sa.student = :student')
            ->andWhere('sa.validatedAt IS NOT NULL')
            ->setParameter('assignments', $assignments)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['assignmentId']] = $row['validatedAt'];
        }

        return $dates;
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
