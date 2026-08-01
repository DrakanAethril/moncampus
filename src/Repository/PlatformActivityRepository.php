<?php

namespace App\Repository;

use App\Entity\PlatformActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformActivity>
 */
class PlatformActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformActivity::class);
    }

    /** @return list<PlatformActivity> */
    public function findLatest(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // Purge de la rétention glissante - voir App\Command\PurgePlatformActivityCommand. DQL DELETE
    // plutôt qu'un chargement puis remove() : la table est faite pour grossir, hydrater des
    // dizaines de milliers d'entités pour les supprimer n'aurait pas de sens.
    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->where('a.occurredAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
