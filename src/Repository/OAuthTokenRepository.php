<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthToken>
 */
class OAuthTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthToken::class);
    }

    /**
     * The lookup half of the selector/verifier pair, with its grant and user - the MCP firewall needs
     * both on every call.
     */
    public function findOneBySelector(string $selector): ?OAuthToken
    {
        return $this->createQueryBuilder('t')
            ->addSelect('g', 'u')
            ->join('t.grant', 'g')
            ->join('g.user', 'u')
            ->where('t.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return int how many were removed */
    public function deleteExpiredBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function countExpiredBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
