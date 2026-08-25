<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeatureRoleSetting;
use App\Enum\Feature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeatureRoleSetting>
 */
class FeatureRoleSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeatureRoleSetting::class);
    }

    /**
     * The whole matrix in one query, flattened to the shape App\Security\FeatureResolver reads.
     *
     * A few hundred rows at most (some fifty features by eight roles), so it is fetched whole and
     * memorised in App\Security\FeatureAccess for the request rather than queried per question -
     * the nav asks some forty of them per page. No application cache: `cache.app` has no consumer
     * in this repository and this is not the place to give it one.
     *
     * @return array<string, bool> keyed `"<feature>|<role>"`
     */
    public function matrix(): array
    {
        /** @var list<array{feature: Feature, role: string, enabled: bool}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.feature AS feature', 's.role AS role', 's.enabled AS enabled')
            ->getQuery()
            ->getResult();

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[$row['feature']->value.'|'.$row['role']] = $row['enabled'];
        }

        return $matrix;
    }

    /** @return list<FeatureRoleSetting> */
    public function findForFeature(Feature $feature): array
    {
        /** @var list<FeatureRoleSetting> $rows */
        $rows = $this->findBy(['feature' => $feature]);

        return $rows;
    }
}
