<?php

namespace App\Repository;

use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcoPositionPing>
 */
class EcoPositionPingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcoPositionPing::class);
    }

    /** @return list<EcoPositionPing> */
    public function findForRunner(EcoRunner $runner): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.runner = :runner')
            ->setParameter('runner', $runner)
            ->orderBy('p.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
