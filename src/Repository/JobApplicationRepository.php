<?php

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\JobApplication;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobApplication>
 */
class JobApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobApplication::class);
    }

    /**
     * A student's applications, companies and mails already loaded: screens 2a and 2b group them by
     * company and count their mails, so everything would be an N+1 without this.
     *
     * @return list<JobApplication>
     */
    public function findForStudent(User $student): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('e', 'm')
            ->join('a.enterprise', 'e')
            ->leftJoin('a.emailMessages', 'm')
            ->andWhere('a.student = :student')
            ->setParameter('student', $student)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** The automatic link of screen 3g's first case: this company, this student. */
    public function findOneForStudentAndEnterprise(User $student, Enterprise $enterprise): ?JobApplication
    {
        return $this->findOneBy(['student' => $student, 'enterprise' => $enterprise]);
    }
}
