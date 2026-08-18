<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProxmoxHost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProxmoxHost>
 */
class ProxmoxHostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProxmoxHost::class);
    }

    /**
     * Declared hosts, in the order an administrator arranged them. Deactivated hosts are excluded
     * unless asked for: a host is never deleted, so the list would otherwise keep growing with
     * hypervisors that no longer exist.
     *
     * @return list<ProxmoxHost>
     */
    public function findOrdered(bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('h')
            ->orderBy('h.position', 'ASC')
            ->addOrderBy('h.label', 'ASC');

        if (!$includeInactive) {
            $qb->andWhere('h.inactiveDate IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->andWhere('h.inactiveDate IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** The next free display slot, so a freshly declared host lands at the end of the list. */
    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('h')
            ->select('MAX(h.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return \is_numeric($max) ? ((int) $max) + 1 : 0;
    }
}
