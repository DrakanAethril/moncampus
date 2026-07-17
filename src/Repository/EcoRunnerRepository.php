<?php

namespace App\Repository;

use App\Entity\EcoRunner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcoRunner>
 */
class EcoRunnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcoRunner::class);
    }

    public function findOneByJoinToken(string $joinToken): ?EcoRunner
    {
        return $this->createQueryBuilder('r')
            ->where('r.joinToken = :token')
            ->setParameter('token', $joinToken)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
