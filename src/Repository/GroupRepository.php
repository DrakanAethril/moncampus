<?php

namespace App\Repository;

use App\Entity\Group;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    public function findOneByLdapCn(string $ldapCn): ?Group
    {
        return $this->findOneBy(['ldapCn' => $ldapCn]);
    }

    // Powers the "secondary groups" chip picker on the user creation form
    // (App\Form\LdapManageUserType, App\Controller\DirectoryUserController::new()) - active
    // groups (LDAP-mirrored or local-only alike) bucketed by GroupType, alphabetically, with any
    // ungrouped ones collected into one trailing bucket (label null - the template renders that
    // as "Autres") rather than interleaved alphabetically, since a catch-all reads better last.
    //
    // $excludedNames is applied here (not left to each caller to re-filter) so the form's choice
    // list and the template's display buckets can never drift apart - LdapManageUserType passes
    // LdapManageUser::USER_TYPES here (primary-group-shaped groups like "teacher"/"student" are
    // also mirrored into this table by App\Security\LdapUserMapper, since create_user.sh adds the
    // account as an explicit member of its own primary group too - but they're selected via the
    // separate userType dropdown, not offered again as a secondary group).
    /**
     * @param list<string> $excludedNames
     *
     * @return list<array{label: ?string, groups: list<Group>}>
     */
    public function findAllActiveGroupedByType(array $excludedNames = []): array
    {
        $groups = $this->createQueryBuilder('g')
            ->leftJoin('g.groupType', 'gt')->addSelect('gt')
            ->where('g.inactiveDate IS NULL')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        $byType = [];
        $untyped = [];

        foreach ($groups as $group) {
            if (\in_array($group->getName(), $excludedNames, true)) {
                continue;
            }

            $typeName = $group->getGroupType()?->getName();

            if (null === $typeName) {
                $untyped[] = $group;
            } else {
                $byType[$typeName][] = $group;
            }
        }

        ksort($byType, \SORT_NATURAL | \SORT_FLAG_CASE);

        $buckets = array_map(
            static fn (string $label, array $typeGroups): array => ['label' => $label, 'groups' => $typeGroups],
            array_keys($byType),
            array_values($byType),
        );

        if ([] !== $untyped) {
            $buckets[] = ['label' => null, 'groups' => $untyped];
        }

        return $buckets;
    }

    // Powers the group-assignment picker on the user edit page (App\Controller\UserManagementController)
    // - only groups staff opted into manual assignment, active ones only.
    /** @return list<Group> */
    public function findAllManuallyAssignable(): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.manuallyAssignable = true')
            ->andWhere('g.inactiveDate IS NULL')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('g')->select('COUNT(g.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Group> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('g.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('g.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('g.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('g.name LIKE :search OR g.role LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - matches every other
    // structural entity's settings list (see e.g. RoomRepository).
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('g.inactiveDate IS NULL');
        }
    }
}
