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
}
