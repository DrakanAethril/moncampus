<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveySeries;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveySeries>
 */
class SurveySeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveySeries::class);
    }

    /**
     * The series a given author sees on the Campagnes tab, newest first, with their waves already
     * loaded - the tab groups campaigns by series, so lazily loading each series' campaigns would
     * be one query per row.
     *
     * @return list<SurveySeries>
     */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.campaigns', 'c')->addSelect('c')
            ->where('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('s.creationDate', 'DESC')
            ->addOrderBy('c.waveNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every series, for staff - a satisfaction survey is an institution-wide object and a manager
     * who cannot read it is a broken feature (surveys.md §6).
     *
     * @return list<SurveySeries>
     */
    public function findAllWithCampaigns(): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.campaigns', 'c')->addSelect('c')
            ->orderBy('s.creationDate', 'DESC')
            ->addOrderBy('c.waveNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
