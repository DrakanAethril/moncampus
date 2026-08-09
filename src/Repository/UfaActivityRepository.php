<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\UfaActivity;
use App\Enum\UfaActivityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UfaActivity>
 */
class UfaActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UfaActivity::class);
    }

    // Les derniers événements d'une alternance, du plus récent au plus ancien - de quoi bâtir un
    // écran de suivi le jour venu, sans que ce journal n'en impose la forme.
    /** @return list<UfaActivity> */
    public function findLatestForTutorLink(InternshipTutorLink $tutorLink, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.tutorLink = :tutorLink')
            ->setParameter('tutorLink', $tutorLink)
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // Le flux transverse, cloisonné test/réel comme le reste de l'UFA.
    /** @return list<UfaActivity> */
    public function findLatest(int $limit = 50, bool $testData = false): array
    {
        return $this->searchQueryBuilder($testData)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Une page de l'historique filtré. $search porte sur l'instantané de noms du payload plutôt
     * que sur les comptes liés : c'est ce que la ligne affiche, et ça reste trouvable après un
     * renommage ou une désactivation - ce pour quoi le payload existe.
     *
     * @return list<UfaActivity>
     */
    public function search(bool $testData, ?UfaActivityType $type, ?Program $program, ?string $search, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit, int $offset): array
    {
        return $this->applyFilters($this->searchQueryBuilder($testData), $type, $program, $search, $from, $to)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countSearch(bool $testData, ?UfaActivityType $type, ?Program $program, ?string $search, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        return (int) $this->applyFilters($this->createQueryBuilder('a')->select('COUNT(a.id)')->where('a.testData = :testData')->setParameter('testData', $testData), $type, $program, $search, $from, $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function searchQueryBuilder(bool $testData): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->addSelect('actor', 'p')
            ->leftJoin('a.actor', 'actor')
            ->leftJoin('a.program', 'p')
            ->where('a.testData = :testData')
            ->setParameter('testData', $testData)
            // L'id départage les lignes d'une même seconde - sans lui, la pagination peut répéter
            // ou sauter une ligne d'une page à l'autre.
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');
    }

    private function applyFilters(QueryBuilder $qb, ?UfaActivityType $type, ?Program $program, ?string $search, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): QueryBuilder
    {
        if (null !== $type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        if (null !== $program) {
            $qb->andWhere('a.program = :program')->setParameter('program', $program);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('a.payload LIKE :search')->setParameter('search', '%'.$search.'%');
        }

        if (null !== $from) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', $from);
        }

        if (null !== $to) {
            $qb->andWhere('a.occurredAt <= :to')->setParameter('to', $to);
        }

        return $qb;
    }
}
