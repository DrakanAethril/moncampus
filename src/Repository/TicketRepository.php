<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function countForReporter(User $reporter, ?string $search = null): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->leftJoin('t.category', 'c')
            ->andWhere('t.reporter = :reporter')->setParameter('reporter', $reporter);
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Ticket> */
    public function findPageForReporter(User $reporter, int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->baseQueryBuilder()
            ->andWhere('t.reporter = :reporter')->setParameter('reporter', $reporter)
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    public function countAll(?string $search = null, ?string $status = null, ?int $categoryId = null, ?string $priority = null, ?int $assigneeId = null): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->leftJoin('t.category', 'c');
        $this->applySearch($qb, $search);
        $this->applyQueueFilters($qb, $status, $categoryId, $priority, $assigneeId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Ticket> */
    public function findPage(int $offset, int $limit, ?string $search = null, ?string $status = null, ?int $categoryId = null, ?string $priority = null, ?int $assigneeId = null): array
    {
        $qb = $this->baseQueryBuilder()
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyQueueFilters($qb, $status, $categoryId, $priority, $assigneeId);

        return $qb->getQuery()->getResult();
    }

    private function baseQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')->addSelect('c')
            ->leftJoin('t.room', 'r')->addSelect('r')
            ->leftJoin('t.reporter', 'rep')->addSelect('rep')
            ->leftJoin('t.assignee', 'a')->addSelect('a')
            ->orderBy('t.id', 'DESC');
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        // Relies on 'c' (t.category) already being joined by the caller.
        $qb->andWhere('t.subject LIKE :search OR c.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyQueueFilters(QueryBuilder $qb, ?string $status, ?int $categoryId, ?string $priority, ?int $assigneeId): void
    {
        if (null !== $status && '' !== $status) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }

        if (null !== $categoryId) {
            $qb->andWhere('t.category = :categoryId')->setParameter('categoryId', $categoryId);
        }

        if (null !== $priority && '' !== $priority) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $priority);
        }

        if (null !== $assigneeId) {
            $qb->andWhere('t.assignee = :assigneeId')->setParameter('assigneeId', $assigneeId);
        }
    }
}
