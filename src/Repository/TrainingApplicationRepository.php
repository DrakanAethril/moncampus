<?php

namespace App\Repository;

use App\Entity\TrainingApplication;
use App\Entity\TrainingOffer;
use App\Entity\User;
use App\Enum\TrainingApplicationState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingApplication>
 */
class TrainingApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingApplication::class);
    }

    /** @return list<TrainingApplication> */
    public function findForStudent(User $student): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o', 'v', 'r')
            ->join('a.offer', 'o')
            ->leftJoin('a.versions', 'v')
            ->leftJoin('a.reviews', 'r')
            ->andWhere('a.student = :student')
            ->setParameter('student', $student)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForStudentAndOffer(User $student, TrainingOffer $offer): ?TrainingApplication
    {
        return $this->findOneBy(['student' => $student, 'offer' => $offer]);
    }

    /**
     * The queue of screen 8c: applications of these students, on offers this teacher validates, and
     * only those waiting on a validator - one being corrected by its student is not theirs to act
     * on.
     *
     * @param list<User> $students
     *
     * @return list<TrainingApplication>
     */
    public function findAwaitingReview(array $students, User $validator): array
    {
        if ([] === $students) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o', 's', 'v')
            ->join('a.offer', 'o')
            ->join('o.validators', 'ov')
            ->join('a.student', 's')
            ->leftJoin('a.versions', 'v')
            ->andWhere('a.student IN (:students)')
            ->andWhere('ov = :validator')
            ->andWhere('a.state IN (:states)')
            ->setParameter('students', $students)
            ->setParameter('validator', $validator)
            ->setParameter('states', [TrainingApplicationState::Received, TrainingApplicationState::Resent])
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Does this student have a fully validated application - the one fact that unlocks sending? */
    public function hasValidatedApplication(User $student): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.student = :student')
            ->andWhere('a.state = :state')
            ->setParameter('student', $student)
            ->setParameter('state', TrainingApplicationState::Validated)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
