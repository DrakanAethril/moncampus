<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassBoardPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassBoardPhoto>
 */
class ClassBoardPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassBoardPhoto::class);
    }

    public function findForDay(\DateTimeImmutable $day): ?ClassBoardPhoto
    {
        return $this->findOneBy(['day' => $day->setTime(0, 0)]);
    }

    /**
     * What a board shows: the photograph of the day, or the latest one still stored when today's
     * has not been fetched yet (Commons unreachable, the small hours before the first pass).
     */
    public function findShown(\DateTimeImmutable $today): ?ClassBoardPhoto
    {
        $photo = $this->createQueryBuilder('p')
            ->where('p.day <= :today')
            ->andWhere('p.storageKey IS NOT NULL')
            ->setParameter('today', $today->setTime(0, 0))
            ->orderBy('p.day', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $photo instanceof ClassBoardPhoto ? $photo : null;
    }

    /**
     * The photographs shown since that day - not to be served again.
     *
     * @return list<string> Commons titles
     */
    public function titlesShownSince(\DateTimeImmutable $since): array
    {
        /** @var list<string> $titles */
        $titles = $this->createQueryBuilder('p')
            ->select('p.commonsTitle')
            ->where('p.day >= :since')
            ->setParameter('since', $since->setTime(0, 0))
            ->getQuery()
            ->getSingleColumnResult();

        return $titles;
    }

    /**
     * The rows whose bytes are still stored although their day is older than that one.
     *
     * @return list<ClassBoardPhoto>
     */
    public function findStoredBefore(\DateTimeImmutable $day): array
    {
        /** @var list<ClassBoardPhoto> $rows */
        $rows = $this->createQueryBuilder('p')
            ->where('p.day < :day')
            ->andWhere('p.storageKey IS NOT NULL')
            ->setParameter('day', $day->setTime(0, 0))
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
