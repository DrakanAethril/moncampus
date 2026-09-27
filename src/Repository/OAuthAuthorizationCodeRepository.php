<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthAuthorizationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthAuthorizationCode>
 */
class OAuthAuthorizationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthAuthorizationCode::class);
    }

    public function findOneBySelector(string $selector): ?OAuthAuthorizationCode
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    /** @return int how many were removed */
    public function deleteExpiredBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
