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
     * Les démarches d'un élève, entreprises et mails déjà chargés : les écrans 2a et 2b les
     * regroupent par entreprise et comptent leurs mails, donc tout ferait N+1 sans ça.
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

    /** Le rattachement automatique du cas 1 de l'écran 3g : cette entreprise, cet élève. */
    public function findOneForStudentAndEnterprise(User $student, Enterprise $enterprise): ?JobApplication
    {
        return $this->findOneBy(['student' => $student, 'enterprise' => $enterprise]);
    }
}
