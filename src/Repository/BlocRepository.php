<?php

namespace App\Repository;

use App\Entity\Bloc;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bloc>
 */
class BlocRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bloc::class);
    }

    /** @return list<Bloc> */
    public function findAllActiveOrderedByCode(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.inactiveDate IS NULL')
            ->orderBy('b.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Bloc> */
    public function findAllOrderedByCode(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
