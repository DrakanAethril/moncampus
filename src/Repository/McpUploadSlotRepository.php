<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\McpUploadSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpUploadSlot>
 */
class McpUploadSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpUploadSlot::class);
    }

    /** The lookup half of the selector/verifier pair, with the connection and its teacher. */
    public function findOneBySelector(string $selector): ?McpUploadSlot
    {
        return $this->createQueryBuilder('s')
            ->addSelect('g', 'u')
            ->join('s.grant', 'g')
            ->join('g.user', 'u')
            ->where('s.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Spends the address - true for the one request that got there first. A conditional UPDATE
     * rather than a flag set on the entity: two PUTs read the same unused row, only one of them
     * changes it.
     */
    public function claim(McpUploadSlot $slot, \DateTimeImmutable $now): bool
    {
        $claimed = $this->createQueryBuilder('s')
            ->update()
            ->set('s.usedAt', ':now')
            ->where('s.id = :id')
            ->andWhere('s.usedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('id', $slot->getId())
            ->getQuery()
            ->execute();

        return 1 === $claimed;
    }

    /** @return int how many were removed */
    public function deleteExpiredBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function countExpiredBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
