<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Entity\VmBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestAccount>
 */
class GuestAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestAccount::class);
    }

    /**
     * Every account MonCampus knows about on one machine - identified by (host, node, vmid) rather
     * than by a relation, since no machine has a row here.
     *
     * @return list<GuestAccount>
     */
    public function findForMachine(ProxmoxHost $host, string $node, int $vmid): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.host = :host')
            ->andWhere('a.node = :node')
            ->andWhere('a.vmid = :vmid')
            ->setParameter('host', $host)
            ->setParameter('node', $node)
            ->setParameter('vmid', $vmid)
            ->orderBy('a.login', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForMachine(ProxmoxHost $host, string $node, int $vmid, string $login): ?GuestAccount
    {
        return $this->findOneBy(['host' => $host, 'node' => $node, 'vmid' => $vmid, 'login' => $login]);
    }

    /** @return list<GuestAccount> */
    public function findForBatch(VmBatch $batch): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.batch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('a.vmid', 'ASC')
            ->addOrderBy('a.login', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** How many machines of a host MonCampus holds accounts for - shown next to each row. */
    public function countByVmid(ProxmoxHost $host): array
    {
        /** @var list<array{vmid: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.vmid AS vmid, COUNT(a.id) AS total')
            ->andWhere('a.host = :host')
            ->setParameter('host', $host)
            ->groupBy('a.vmid')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['vmid']] = (int) $row['total'];
        }

        return $counts;
    }
}
