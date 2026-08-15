<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentationCounterReset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentationCounterReset>
 */
class DocumentationCounterResetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentationCounterReset::class);
    }

    public function findLast(): ?DocumentationCounterReset
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.resetAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
