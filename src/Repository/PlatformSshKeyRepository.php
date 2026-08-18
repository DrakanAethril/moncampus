<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlatformSshKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformSshKey>
 */
class PlatformSshKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformSshKey::class);
    }

    /** The key in use. Null before one has ever been generated - a state the screens must show. */
    public function findActive(): ?PlatformSshKey
    {
        return $this->createQueryBuilder('k')
            ->andWhere('k.active = true')
            ->orderBy('k.creationDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every key a machine might still be trusting - the active one first.
     *
     * A rotation posts the new key, verifies it, and only then retires the old one, so during that
     * window a machine may accept either. Trying them in this order is what lets a rotation reach
     * the machines that were offline when it started.
     *
     * @return list<PlatformSshKey>
     */
    public function findUsable(): array
    {
        return $this->createQueryBuilder('k')
            ->orderBy('k.active', 'DESC')
            ->addOrderBy('k.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
