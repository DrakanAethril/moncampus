<?php

declare(strict_types=1);

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
        return $this->groupActiveByType($excludedNames, onlyManuallyAssignable: false);
    }

    // Powers the "additional groups" chip picker on the user edit page
    // (App\Form\UserProfileType, App\Controller\DirectoryUserController::edit()) - same bucketing
    // as findAllActiveGroupedByType() above, but scoped to groups staff opted into manual
    // assignment (Settings > Groups), since an LDAP-mirrored group's real membership is fully
    // annuaire-owned and read-only from this screen (rendered separately as locked chips).
    // $excludedNames is used here for the caller's own per-user LDAP-inherited group names (so a
    // group can't be offered as "additional" while already granted via the annuaire), not the
    // static admin/userType exclusion the create-mode picker above uses.
    /**
     * @param list<string> $excludedNames
     *
     * @return list<array{label: ?string, groups: list<Group>}>
     */
    public function findManuallyAssignableGroupedByType(array $excludedNames = []): array
    {
        return $this->groupActiveByType($excludedNames, onlyManuallyAssignable: true);
    }

    /**
     * @param list<string> $excludedNames
     *
     * @return list<array{label: ?string, groups: list<Group>}>
     */
    private function groupActiveByType(array $excludedNames, bool $onlyManuallyAssignable): array
    {
        // gt.order first (bucket order), gt.id to break ties, g.name last (order of groups within
        // a bucket) - groups with no GroupType sort as NULL and are pulled out into $untyped below
        // anyway, so their position relative to typed groups here doesn't matter.
        //
        // The gt.id tiebreak has to match GroupTypeRepository's: without it, two types sharing an
        // order fall through to g.name here and to the storage order there, and the buckets on the
        // user-creation picker come out in a different order than the settings list they're meant
        // to mirror. Version20260731160000 removed the ties this used to hit in practice (every
        // pre-drag-and-drop type sat at order 0); the tiebreak keeps a future one harmless.
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.groupType', 'gt')->addSelect('gt')
            ->where('g.inactiveDate IS NULL')
            ->orderBy('gt.order', 'ASC')
            ->addOrderBy('gt.id', 'ASC')
            ->addOrderBy('g.name', 'ASC');

        if ($onlyManuallyAssignable) {
            $qb->andWhere('g.manuallyAssignable = true');
        }

        $groups = $qb->getQuery()->getResult();

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

    // The whole hierarchy as App\Service\GroupHierarchy reads it: one lightweight query, every row
    // (inactive included - a chain must not appear broken just because a middle group was
    // deactivated), id => parent id or null.
    /** @return array<int, int|null> */
    public function findParentMap(): array
    {
        /** @var list<array{id: int, parentId: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->select('g.id AS id, IDENTITY(g.parent) AS parentId')
            ->getQuery()
            ->getArrayResult();

        $parentMap = [];

        // IDENTITY() comes back as a string on MySQL - typed here rather than at each caller.
        foreach ($rows as $row) {
            $parentMap[$row['id']] = null === $row['parentId'] ? null : (int) $row['parentId'];
        }

        return $parentMap;
    }

    // Active groups in the settings screens' usual reading order (GroupType's drag-and-drop order
    // first, then name) - the parent picker's choice list and the hierarchy tab's rows both start
    // from this, the tab then re-ordering it into a tree via App\Service\GroupHierarchy::flatten().
    /** @return list<Group> */
    public function findActiveOrderedByType(): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.groupType', 'gt')->addSelect('gt')
            ->leftJoin('g.parent', 'p')->addSelect('p')
            ->where('g.inactiveDate IS NULL')
            ->orderBy('gt.order', 'ASC')
            ->addOrderBy('gt.id', 'ASC')
            ->addOrderBy('g.name', 'ASC')
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
            ->leftJoin('g.parent', 'p')->addSelect('p')
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
