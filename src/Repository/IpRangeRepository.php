<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IpRange>
 */
class IpRangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpRange::class);
    }

    /** @return list<IpRange> */
    public function findOrdered(bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.host', 'h')->addSelect('h')
            ->orderBy('h.position', 'ASC')
            ->addOrderBy('r.label', 'ASC');

        if (!$includeInactive) {
            $qb->andWhere('r.inactiveDate IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The ranges the creation wizard may offer on a host - a range belongs to one host, since there
     * is no aggregated multi-host addressing by design.
     *
     * @return list<IpRange>
     */
    public function findActiveForHost(ProxmoxHost $host): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.host = :host')
            ->andWhere('r.inactiveDate IS NULL')
            ->setParameter('host', $host)
            ->orderBy('r.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.inactiveDate IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
