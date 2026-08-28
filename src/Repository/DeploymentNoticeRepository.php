<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeploymentNotice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeploymentNotice>
 */
class DeploymentNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeploymentNotice::class);
    }

    /**
     * The deployment currently under way, or null.
     *
     * Both halves of « under way » are asked of the database rather than of the rows: a notice
     * nobody closed is not current either, and reading the open ones to filter them in PHP would
     * mean reading every deployment ever announced.
     */
    public function findCurrent(\DateTimeImmutable $now): ?DeploymentNotice
    {
        return $this->createQueryBuilder('n')
            ->where('n.finishedAt IS NULL')
            ->andWhere('n.expiresAt > :now')
            ->setParameter('now', $now)
            ->orderBy('n.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every notice still open, newest first - what open() closes before raising its own.
     *
     * @return list<DeploymentNotice>
     */
    public function findAllOpen(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.finishedAt IS NULL')
            ->andWhere('n.expiresAt > :now')
            ->setParameter('now', $now)
            ->orderBy('n.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
