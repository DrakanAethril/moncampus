<?php

namespace App\Repository;

use App\Entity\Enterprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enterprise>
 */
class EnterpriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enterprise::class);
    }

    // Powers InternshipTutorLinkType's "pick an existing enterprise" dropdown.
    /** @return list<Enterprise> */
    public function findAllActiveOrderedByName(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.inactiveDate IS NULL')
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
