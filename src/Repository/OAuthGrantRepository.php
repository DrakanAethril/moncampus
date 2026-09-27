<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthGrant;
use App\Entity\OAuthToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthGrant>
 */
class OAuthGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthGrant::class);
    }

    /**
     * The connections « Mon profil » lists: not revoked, and still holding a token that has not
     * expired. A consent whose code was never exchanged, or whose refresh token ran out, opens
     * nothing any more and is not worth a « Révoquer » button.
     *
     * @return list<OAuthGrant>
     */
    public function findLiveFor(User $user, \DateTimeImmutable $now): array
    {
        $liveToken = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(OAuthToken::class, 't')
            ->where('t.grant = g')
            ->andWhere('t.expiresAt > :now')
            ->andWhere('t.rotatedAt IS NULL')
            ->getDQL();

        return $this->createQueryBuilder('g')
            ->addSelect('c')
            ->join('g.client', 'c')
            ->where('g.user = :user')
            ->andWhere('g.revokedAt IS NULL')
            ->andWhere(\sprintf('EXISTS (%s)', $liveToken))
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
