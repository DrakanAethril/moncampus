<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProxmoxOperation>
 */
class ProxmoxOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProxmoxOperation::class);
    }

    /**
     * @return list<ProxmoxOperation>
     */
    public function findPage(int $offset, int $limit, ?ProxmoxHost $host = null, ?ProxmoxAction $action = null, ?ProxmoxOperationStatus $status = null, string $search = ''): array
    {
        return $this->filtered($host, $action, $status, $search)
            ->orderBy('o.requestedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(?ProxmoxHost $host = null, ?ProxmoxAction $action = null, ?ProxmoxOperationStatus $status = null, string $search = ''): int
    {
        return (int) $this->filtered($host, $action, $status, $search)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The same, for every host at once: the machines list is no longer about one of them.
     *
     * @return array<int, array<int, ProxmoxOperation>> host id => vmid => operation
     */
    public function findUnsettledByHostAndVmid(): array
    {
        /** @var list<ProxmoxOperation> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.status IN (:live)')
            ->andWhere('o.vmid IS NOT NULL')
            ->andWhere('o.host IS NOT NULL')
            ->setParameter('live', [ProxmoxOperationStatus::Pending, ProxmoxOperationStatus::Running])
            ->orderBy('o.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byHost = [];

        foreach ($rows as $operation) {
            $vmid = $operation->getVmid();
            $host = $operation->getHost();

            if (null !== $vmid && null !== $host) {
                // Latest wins: two live operations on one machine is not a state to display twice.
                $byHost[(int) $host->getId()][$vmid] = $operation;
            }
        }

        return $byHost;
    }

    /**
     * The operations still under way on a host, keyed by VMID - what the machines list needs to
     * show "shutting down, asked for by X, 8 seconds ago" on the right row without a query per row.
     *
     * @return array<int, ProxmoxOperation>
     */
    public function findUnsettledByVmid(ProxmoxHost $host): array
    {
        /** @var list<ProxmoxOperation> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.host = :host')
            ->andWhere('o.status IN (:live)')
            ->andWhere('o.vmid IS NOT NULL')
            ->setParameter('host', $host)
            ->setParameter('live', [ProxmoxOperationStatus::Pending, ProxmoxOperationStatus::Running])
            ->orderBy('o.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byVmid = [];
        foreach ($rows as $operation) {
            $vmid = $operation->getVmid();
            if (null !== $vmid) {
                // Latest wins: two live operations on one machine is not a state to display twice.
                $byVmid[$vmid] = $operation;
            }
        }

        return $byVmid;
    }

    /**
     * Operations left hanging - the request went out, the answer never came. Used by the tracker
     * to settle them as `unknown` rather than leaving a row that polls for ever.
     *
     * @return list<ProxmoxOperation>
     */
    public function findStale(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status IN (:live)')
            ->andWhere('o.requestedAt < :before')
            ->setParameter('live', [ProxmoxOperationStatus::Pending, ProxmoxOperationStatus::Running])
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filtered(?ProxmoxHost $host, ?ProxmoxAction $action, ?ProxmoxOperationStatus $status, string $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o');

        if (null !== $host) {
            $qb->andWhere('o.host = :host')->setParameter('host', $host);
        }

        if (null !== $action) {
            $qb->andWhere('o.action = :action')->setParameter('action', $action);
        }

        if (null !== $status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        if ('' !== $search) {
            $qb->andWhere('o.guestName LIKE :search OR o.requestedByLabel LIKE :search OR o.hostLabel LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }
}
