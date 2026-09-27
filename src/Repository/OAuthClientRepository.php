<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthClient>
 */
class OAuthClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthClient::class);
    }

    public function findOneByClientId(string $clientId): ?OAuthClient
    {
        return '' === $clientId ? null : $this->findOneBy(['clientId' => $clientId]);
    }

    /**
     * Removes the clients registered before `$before` that nobody ever consented to. Registration is
     * open to the whole internet by specification, so this is what keeps the table from growing with
     * every probe - a client somebody did connect stays, its grants being the trace.
     *
     * @return int how many were removed
     */
    public function deleteUnconsentedBefore(\DateTimeImmutable $before): int
    {
        $consented = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(g.client)')
            ->from(OAuthGrant::class, 'g')
            ->getDQL();

        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.createdAt < :before')
            ->andWhere(\sprintf('c.id NOT IN (%s)', $consented))
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
