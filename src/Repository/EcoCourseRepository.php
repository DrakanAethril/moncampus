<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCourseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcoCourse>
 */
class EcoCourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcoCourse::class);
    }

    /** @return list<EcoCourse> */
    public function findForParcours(EcoParcours $parcours): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parcours = :parcours')
            ->setParameter('parcours', $parcours)
            ->orderBy('c.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCode(string $code): ?EcoCourse
    {
        return $this->createQueryBuilder('c')
            ->where('c.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Teacher mobile app's live-tracking entry list (screen 4d) - every InProgress course across
    // any of this teacher's parcours - created or shared with them - not just the courses they
    // personally started.
    /** @return list<EcoCourse> */
    public function findInProgressForTeacher(User $teacher): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.parcours', 'p')
            ->where('p.teacher = :teacher OR :teacher MEMBER OF p.sharedWith')
            ->andWhere('c.status = :status')
            ->setParameter('teacher', $teacher)
            ->setParameter('status', EcoCourseStatus::InProgress)
            ->orderBy('c.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
