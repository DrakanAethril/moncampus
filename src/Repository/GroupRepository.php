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

    // Powers UserManagementController::data()'s "groupes de l'annuaire" column - resolves each
    // raw ROLE_ string in User::getLdapRoles() back to the Group it was mirrored from
    // (App\Security\LdapUserMapper always upserts a Group row with a matching role before adding
    // it to a user's roles, so this lookup should never miss). Includes inactive groups too -
    // this is a display-only lookup, not a permission check, and a role already granted to a
    // user shouldn't silently stop resolving to a name just because the Group row was later
    // deactivated.
    /** @return array<string, Group> */
    public function findAllIndexedByRole(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $group) {
            $indexed[$group->getRole()] = $group;
        }

        return $indexed;
    }

    // Powers the "secondary groups" chip picker on the user creation form
    // (App\Form\LdapManageUserType, App\Controller\DirectoryUserController::new()) - active
    // groups (LDAP-mirrored or local-only alike) bucketed by GroupType, in the drag-and-drop
    // order set on /settings/groups/types (App\Entity\GroupType::$order), with any ungrouped
    // ones collected into one trailing bucket (label null - the template renders that as
    // "Autres") rather than interleaved with the rest, since a catch-all reads better last.
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
        // gt.order first (bucket order), g.name second (order of groups within a bucket) -
        // groups with no GroupType sort as NULL and are pulled out into $untyped below anyway,
        // so their position relative to typed groups here doesn't matter.
        $groups = $this->createQueryBuilder('g')
            ->leftJoin('g.groupType', 'gt')->addSelect('gt')
            ->where('g.inactiveDate IS NULL')
            ->orderBy('gt.order', 'ASC')
            ->addOrderBy('g.name', 'ASC')
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
