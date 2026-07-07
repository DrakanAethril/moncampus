<?php

namespace App\Repository;

use App\Entity\InternshipFormationCenter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipFormationCenter>
 */
class InternshipFormationCenterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipFormationCenter::class);
    }

    // There's exactly one row for this single-tenant installation - fetch it, or create it on
    // first access so the settings form always has something to bind to.
    public function getOrCreate(): InternshipFormationCenter
    {
        $existing = $this->findSingleton();

        if (null !== $existing) {
            return $existing;
        }

        $formationCenter = new InternshipFormationCenter();
        $this->getEntityManager()->persist($formationCenter);

        return $formationCenter;
    }

    // Read-only variant for callers (e.g. the booklet builder) that must not risk persisting a
    // blank row as a side effect of a view action - null just means "not filled in yet".
    public function findSingleton(): ?InternshipFormationCenter
    {
        return $this->createQueryBuilder('c')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
