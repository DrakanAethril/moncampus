<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserFeatureAccess;
use App\Enum\Feature;
use App\Enum\FeatureAccessState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserFeatureAccess>
 */
class UserFeatureAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserFeatureAccess::class);
    }

    /**
     * One person's derogations, in the shape App\Security\FeatureResolver reads.
     *
     * @return array<string, FeatureAccessState> keyed by feature value
     */
    public function statesFor(User $user): array
    {
        /** @var list<array{feature: Feature, state: FeatureAccessState}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.feature AS feature', 'a.state AS state')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $states = [];
        foreach ($rows as $row) {
            $states[$row['feature']->value] = $row['state'];
        }

        return $states;
    }

    /**
     * How many people carry a derogation on each feature - the counter the matrix screen shows
     * next to every line, and the only thing that says "this row is not the whole story".
     *
     * @return array<string, int> keyed by feature value, features with none omitted
     */
    public function countsByFeature(): array
    {
        /** @var list<array{feature: Feature, total: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.feature AS feature', 'COUNT(a.id) AS total')
            ->groupBy('a.feature')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['feature']->value] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return list<UserFeatureAccess> */
    public function findForFeatureWithUsers(Feature $feature): array
    {
        /** @var list<UserFeatureAccess> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.user', 'u')
            ->where('a.feature = :feature')
            ->setParameter('feature', $feature)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneFor(User $user, Feature $feature): ?UserFeatureAccess
    {
        return $this->findOneBy(['user' => $user, 'feature' => $feature]);
    }
}
