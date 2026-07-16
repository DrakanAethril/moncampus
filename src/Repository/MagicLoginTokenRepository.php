<?php

namespace App\Repository;

use App\Entity\MagicLoginToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagicLoginToken>
 */
class MagicLoginTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagicLoginToken::class);
    }

    public function findOneBySelector(string $selector): ?MagicLoginToken
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    // Atomic "consume if still pending" - the WHERE usedAt IS NULL makes this a no-op (0 rows
    // affected) for whichever of two concurrent requests loses the race, even though both may
    // have already passed App\Service\MagicLoginService::consume()'s in-PHP checks. Bypasses the
    // UnitOfWork/identity map on purpose (a plain $token->markUsed() + flush() isn't atomic
    // across requests) but keeps the in-memory entity in sync so callers see the change either way.
    public function markUsed(MagicLoginToken $token): bool
    {
        $affected = $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':now')
            ->where('t.id = :id')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute();

        if ($affected > 0) {
            $token->markUsed();

            return true;
        }

        return false;
    }

    // Called before issuing a fresh token (App\Service\MagicLoginService::requestLink()) so at
    // most one magic link is ever live for a given user at a time - keeps the exposure window
    // (an unused link sitting in an inbox) from growing every time someone re-requests one.
    public function deletePendingForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.user = :user')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
