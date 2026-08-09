<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobSearchNote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobSearchNote>
 */
class JobSearchNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobSearchNote::class);
    }

    /**
     * Newest first: on screen 2a these notes are read as a running log, and what was just written
     * about a student is what the next teacher needs first.
     *
     * @return list<JobSearchNote>
     */
    public function findForStudent(User $student): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('a')
            ->leftJoin('n.author', 'a')
            ->andWhere('n.student = :student')
            ->setParameter('student', $student)
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
