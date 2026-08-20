<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveyCampaign;
use App\Entity\SurveySeries;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyCampaign>
 */
class SurveyCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyCampaign::class);
    }

    /**
     * The waves of a series, oldest first, with their snapshot loaded - the comparison screen
     * reads every wave's questions and would otherwise issue one query per wave.
     *
     * @return list<SurveyCampaign>
     */
    public function findWavesWithQuestions(SurveySeries $series): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.questions', 'q')->addSelect('q')
            ->leftJoin('q.answers', 'a')->addSelect('a')
            ->where('c.series = :series')
            ->setParameter('series', $series)
            ->orderBy('c.waveNumber', 'ASC')
            ->addOrderBy('q.orderIndex', 'ASC')
            ->addOrderBy('a.orderIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The campaigns a given author launched, newest first.
     *
     * @return list<SurveyCampaign>
     */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.series', 's')->addSelect('s')
            ->where('c.createdBy = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('c.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<SurveyCampaign> */
    public function findAllLaunched(): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.series', 's')->addSelect('s')
            ->where('c.targetFrozenAt IS NOT NULL')
            ->orderBy('c.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One campaign with its whole snapshot in a single query - what the respondent's screen and
     * the mobile API both need.
     */
    public function findWithQuestions(int $id): ?SurveyCampaign
    {
        /** @var SurveyCampaign|null $campaign */
        $campaign = $this->createQueryBuilder('c')
            ->leftJoin('c.questions', 'q')->addSelect('q')
            ->leftJoin('q.answers', 'a')->addSelect('a')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->orderBy('q.orderIndex', 'ASC')
            ->addOrderBy('a.orderIndex', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $campaign;
    }

    /** How many launched waves the tab badge shows - every one for staff, one's own otherwise. */
    public function countLaunched(?User $owner): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.targetFrozenAt IS NOT NULL');

        if (null !== $owner) {
            $qb->andWhere('c.createdBy = :owner')->setParameter('owner', $owner);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The subtitle of the index: how many campaigns are open right now, and how many of those still
     * have somebody to remind.
     *
     * @return list<SurveyCampaign>
     */
    public function findOpenFor(?User $owner, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('c')
            ->where('c.targetFrozenAt IS NOT NULL')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('c.opensAt IS NULL OR c.opensAt <= :now')
            ->andWhere('c.closesAt IS NULL OR c.closesAt >= :now')
            ->setParameter('now', $now);

        if (null !== $owner) {
            $qb->andWhere('c.createdBy = :owner')->setParameter('owner', $owner);
        }

        return $qb->getQuery()->getResult();
    }
}
