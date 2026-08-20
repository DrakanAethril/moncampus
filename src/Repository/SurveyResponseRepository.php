<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyResponse;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyResponse>
 */
class SurveyResponseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyResponse::class);
    }

    public function countSubmitted(SurveyCampaign $campaign): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.campaign = :campaign')
            ->andWhere('r.submittedAt IS NOT NULL')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The submitted responses of a campaign, sorted the only way an anonymous campaign may be
     * sorted: by display_key. Sorting by id or by submitted_at is what deanonymises - the first
     * row would be the first person to have answered (surveys.md §7.3).
     *
     * A nominative campaign sorts by respondent name, which is what its reader expects.
     *
     * @return list<SurveyResponse>
     */
    public function findSubmitted(SurveyCampaign $campaign): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.respondent', 'u')->addSelect('u')
            ->where('r.campaign = :campaign')
            ->andWhere('r.submittedAt IS NOT NULL')
            ->setParameter('campaign', $campaign);

        if ($campaign->isAnonymous()) {
            $qb->orderBy('r.displayKey', 'ASC');
        } else {
            $qb->orderBy('u.lastname', 'ASC')->addOrderBy('u.firstname', 'ASC')->addOrderBy('r.displayKey', 'ASC');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The draft this person left behind, if any. On an anonymous campaign a draft cannot be found
     * by respondent - there is none stored - so the caller passes the response id it kept in
     * session instead; see App\Service\Survey\SurveyResponseRecorder.
     */
    public function findDraftFor(SurveyCampaign $campaign, User $respondent): ?SurveyResponse
    {
        return $this->findOneBy(['campaign' => $campaign, 'respondent' => $respondent, 'submittedAt' => null]);
    }
}
