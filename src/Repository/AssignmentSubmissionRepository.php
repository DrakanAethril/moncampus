<?php

namespace App\Repository;

use App\Entity\Assignment;
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

    public function findOneForAssignmentAndStudent(Assignment $assignment, User $student): ?AssignmentSubmission
    {
        return $this->createQueryBuilder('s')
            ->addSelect('f')
            ->leftJoin('s.files', 'f')
            ->where('s.assignment = :assignment')
            ->andWhere('s.student = :student')
            ->setParameter('assignment', $assignment)
            ->setParameter('student', $student)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return array<int, AssignmentSubmission> student id => their submission */
    public function findAllByStudentIdForAssignment(Assignment $assignment): array
    {
        $submissions = $this->createQueryBuilder('s')
            ->addSelect('f')
            ->leftJoin('s.files', 'f')
            ->where('s.assignment = :assignment')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getResult();

        $byStudentId = [];
        foreach ($submissions as $submission) {
            $byStudentId[$submission->getStudent()->getId()] = $submission;
        }

        return $byStudentId;
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
            ->select('IDENTITY(s.assignment) AS assignmentId', 'COUNT(s.id) AS total')
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
            ->select('IDENTITY(s.assignment) AS assignmentId', 'IDENTITY(s.student) AS studentId')
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
}
