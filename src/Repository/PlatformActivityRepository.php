<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlatformActivity;
use App\Enum\PlatformActivityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
        return $this->searchQueryBuilder()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Une page de l'historique filtré - pendant de UfaActivityRepository::search(), mêmes règles :
     * la recherche porte sur l'instantané du payload, pas sur le compte lié.
     *
     * @return list<PlatformActivity>
     */
    public function search(?PlatformActivityType $type, ?string $search, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit, int $offset): array
    {
        return $this->applyFilters($this->searchQueryBuilder(), $type, $search, $from, $to)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countSearch(?PlatformActivityType $type, ?string $search, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        return (int) $this->applyFilters($this->createQueryBuilder('a')->select('COUNT(a.id)'), $type, $search, $from, $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function searchQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->addSelect('actor')
            ->leftJoin('a.actor', 'actor')
            // L'id départage les lignes d'une même seconde, sans quoi la pagination peut répéter
            // ou sauter une ligne - une rafale de connexions tient dans la même seconde.
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');
    }

    private function applyFilters(QueryBuilder $qb, ?PlatformActivityType $type, ?string $search, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): QueryBuilder
    {
        if (null !== $type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('a.payload LIKE :search OR a.ipAddress LIKE :search')->setParameter('search', '%'.$search.'%');
        }

        if (null !== $from) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', $from);
        }

        if (null !== $to) {
            $qb->andWhere('a.occurredAt <= :to')->setParameter('to', $to);
        }

        return $qb;
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
