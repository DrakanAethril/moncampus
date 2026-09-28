<?php

declare(strict_types=1);

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

    /** The parcours whose terrain analysis has waited longest, if any is waiting. */
    public function findNextTerrainRequest(): ?EcoParcours
    {
        return $this->createQueryBuilder('p')
            ->where('p.terrainRequestedAt IS NOT NULL')
            ->orderBy('p.terrainRequestedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
