<?php

namespace App\Repository;

use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
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
}
