<?php

namespace App\Repository;

use App\Entity\InternshipTutorLink;
use App\Entity\UfaActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        return $this->createQueryBuilder('a')
            ->where('a.testData = :testData')
            ->setParameter('testData', $testData)
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
