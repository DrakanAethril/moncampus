<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyTarget;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyTarget>
 */
class SurveyTargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyTarget::class);
    }

    public function findOneFor(SurveyCampaign $campaign, User $user): ?SurveyTarget
    {
        return $this->findOneBy(['campaign' => $campaign, 'user' => $user]);
    }

    /**
     * When this person answered, or null - the proof of completion App\Service\StudentWorkBoard
     * reads for the Survey nature. Nominative even on an anonymous campaign, which is the whole
     * point of splitting the fact of having answered from its content (surveys.md §4).
     */
    public function respondedAt(SurveyCampaign $campaign, User $user): ?\DateTimeImmutable
    {
        return $this->findOneFor($campaign, $user)?->getRespondedAt();
    }

    /** The denominator of the response rate - never the number of students of the class. */
    public function countFor(SurveyCampaign $campaign): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRespondedFor(SurveyCampaign $campaign): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.campaign = :campaign')
            ->andWhere('t.respondedAt IS NOT NULL')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Both counts of the response rate in one query - "18 / 24", the pair every results screen
     * opens on.
     *
     * @return array{targeted: int, responded: int}
     */
    public function responseRate(SurveyCampaign $campaign): array
    {
        /** @var array{targeted: int|string, responded: int|string} $row */
        $row = $this->createQueryBuilder('t')
            ->select('COUNT(t.id) AS targeted')
            ->addSelect('SUM(CASE WHEN t.respondedAt IS NOT NULL THEN 1 ELSE 0 END) AS responded')
            ->where('t.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleResult();

        return ['targeted' => (int) $row['targeted'], 'responded' => (int) $row['responded']];
    }

    /**
     * Who to remind: the targets still without a response, by name.
     *
     * @return list<SurveyTarget>
     */
    public function findPending(SurveyCampaign $campaign): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.user', 'u')->addSelect('u')
            ->where('t.campaign = :campaign')
            ->andWhere('t.respondedAt IS NULL')
            ->setParameter('campaign', $campaign)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everybody aimed at, by name - the Non-répondants tab shows both halves.
     *
     * @return list<SurveyTarget>
     */
    public function findAllFor(SurveyCampaign $campaign): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.user', 'u')->addSelect('u')
            ->where('t.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The ids already in the target, so the refresh of surveys.md §7.2 only ever *adds*.
     *
     * @return list<int>
     */
    public function findTargetedUserIds(SurveyCampaign $campaign): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.user) AS id')
            ->where('t.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['id'], $rows);
    }

    /**
     * The campaigns this person still has to answer: a target row without responded_at, on a
     * campaign that is open right now. The date filtering that state() does in PHP is repeated in
     * DQL here because "Mes sondages" must not load every campaign to discard most of them.
     *
     * @return list<SurveyTarget>
     */
    public function findPendingForUser(User $user, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('t')
            ->join('t.campaign', 'c')->addSelect('c')
            ->where('t.user = :user')
            ->andWhere('t.respondedAt IS NULL')
            ->andWhere('c.targetFrozenAt IS NOT NULL')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('c.opensAt IS NULL OR c.opensAt <= :now')
            ->andWhere('c.closesAt IS NULL OR c.closesAt >= :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('c.closesAt', 'ASC')
            ->addOrderBy('c.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What this person has already answered - the second half of "Mes sondages".
     *
     * @return list<SurveyTarget>
     */
    public function findAnsweredForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.campaign', 'c')->addSelect('c')
            ->where('t.user = :user')
            ->andWhere('t.respondedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('t.respondedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * When this student answered each of the given campaigns - the batch reading
     * App\Service\StudentWorkBoard needs, so that a board holding ten survey assignments still
     * costs one query rather than ten.
     *
     * @param list<SurveyCampaign> $campaigns
     *
     * @return array<int, \DateTimeImmutable> campaign id => the moment it was answered
     */
    public function findRespondedDatesForUser(array $campaigns, User $user): array
    {
        if ([] === $campaigns) {
            return [];
        }

        /** @var list<array{campaignId: int, respondedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.campaign) AS campaignId', 't.respondedAt')
            ->where('t.campaign IN (:campaigns)')
            ->andWhere('t.user = :user')
            ->andWhere('t.respondedAt IS NOT NULL')
            ->setParameter('campaigns', $campaigns)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[$row['campaignId']] = $row['respondedAt'];
        }

        return $dates;
    }
}
