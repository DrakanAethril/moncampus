<?php

namespace App\Repository;

use App\Entity\EcoParcours;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcoParcours>
 */
class EcoParcoursRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcoParcours::class);
    }

    /** @return list<EcoParcours> */
    public function findForTeacher(User $teacher): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('COALESCE(p.lastUpdatedDate, p.creationDate) AS HIDDEN sortDate')
            ->where('p.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('sortDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
