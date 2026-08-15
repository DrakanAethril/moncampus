<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Entity\User;
use App\Repository\GroupRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The perimeter of the documentation base: the branch of the App\Entity\Group hierarchy that
 * starts at "tout le campus" - the group named by the app.documentation_root_group parameter
 * ("campus") - and goes down through filières, classes and options.
 *
 * Nothing here is specific to the documentation except the root: the tree is the very hierarchy
 * staff maintain in Paramètres > Groupes, and an article posted on "BTS SIO" reaches its classes
 * because they hang under it there.
 *
 * Two directions, and mixing them up is the bug to avoid:
 * - branchIds() goes *down* - browsing "BTS SIO" shows the articles of SIO and of its classes;
 * - readerGroupIds() goes *up* - a reader of SIO 2 also matches articles posted on BTS SIO and on
 *   the campus. Today the annuaire already grants every level (a SIO 2 student carries ROLE_SIO
 *   and ROLE_CAMPUS too), so the walk is a safety net rather than the mechanism.
 *
 * Memoised per request and reset between them, for the reason spelled out in
 * App\Service\AudienceResolver: under FrankenPHP worker mode the instance outlives the request,
 * and here that would mean answering one reader's perimeter with another's.
 */
class DocumentationPerimeter implements ResetInterface
{
    /** @var list<Group>|null */
    private ?array $groups = null;

    /** @var array<int, int|null>|null */
    private ?array $parentMap = null;

    /** @var array<int, list<int>> */
    private array $readerGroupIds = [];

    public function __construct(
        private readonly GroupRepository $groupRepository,
        private readonly GroupHierarchy $hierarchy,
        private readonly string $rootGroupName,
    ) {
    }

    public function reset(): void
    {
        $this->groups = null;
        $this->parentMap = null;
        $this->readerGroupIds = [];
    }

    /**
     * "Tout le campus" - the root of the perimeter, or null when no group carries that name (a
     * renamed group, a fresh database). Callers fall back to the whole hierarchy rather than to
     * nothing: an unnamed root is a configuration accident, and hiding every article would be a
     * worse answer than showing the tree as several roots.
     */
    public function root(): ?Group
    {
        foreach ($this->allGroups() as $group) {
            if (0 === strcasecmp($group->getName(), $this->rootGroupName)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The perimeter as a list of rows ready to indent - the left column of 2a/2b and the checkbox
     * tree of 2c/2d, in the same order in all four.
     *
     * @return list<array{group: Group, depth: int}>
     */
    public function tree(): array
    {
        $byId = [];

        foreach ($this->allGroups() as $group) {
            $id = $group->getId();

            if (null !== $id) {
                $byId[$id] = $group;
            }
        }

        $eligible = $this->eligibleIds();
        $rows = [];

        foreach ($this->allGroups() as $group) {
            $id = $group->getId();

            if (null !== $id && \in_array($id, $eligible, true)) {
                $rows[] = ['id' => $id, 'parentId' => $group->getParent()?->getId()];
            }
        }

        $tree = [];

        foreach ($this->hierarchy->flatten($rows) as $flattened) {
            $group = $byId[$flattened['id']] ?? null;

            if (null !== $group) {
                $tree[] = ['group' => $group, 'depth' => $flattened['depth']];
            }
        }

        return $tree;
    }

    /**
     * Every group an article may be posted on: the root's branch, or - when no root is named - the
     * groups that take part in the hierarchy at all (a parent or a child), which leaves the flat
     * role groups (student, teacher, admin...) out where they belong.
     *
     * @return list<int>
     */
    public function eligibleIds(): array
    {
        $root = $this->root();
        $rootId = $root?->getId();

        if (null !== $rootId) {
            return $this->hierarchy->branchIds($rootId, $this->parentMap());
        }

        $parentMap = $this->parentMap();
        $hasChildren = [];

        foreach ($parentMap as $parentId) {
            if (null !== $parentId) {
                $hasChildren[$parentId] = true;
            }
        }

        $ids = [];

        foreach ($parentMap as $id => $parentId) {
            if (null !== $parentId || isset($hasChildren[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The browsed section and everything below it - what a page of garde lists.
     *
     * @return list<int>
     */
    public function branchIds(int $groupId): array
    {
        return $this->hierarchy->branchIds($groupId, $this->parentMap());
    }

    /**
     * The reader's own place in the perimeter: the groups whose role they carry, plus every group
     * above those. An empty list means "belongs nowhere", and nothing is readable - see
     * App\Service\DocumentationAccess.
     *
     * @return list<int>
     */
    public function readerGroupIds(?User $user): array
    {
        if (null === $user) {
            return [];
        }

        $userId = $user->getId();

        if (null !== $userId && isset($this->readerGroupIds[$userId])) {
            return $this->readerGroupIds[$userId];
        }

        $roles = $user->getRoles();
        $eligible = $this->eligibleIds();
        $ids = [];

        foreach ($this->allGroups() as $group) {
            $id = $group->getId();

            if (null === $id || !\in_array($id, $eligible, true) || !\in_array($group->getRole(), $roles, true)) {
                continue;
            }

            $ids[$id] = true;

            foreach ($this->hierarchy->ancestorIds($id, $this->parentMap()) as $ancestorId) {
                $ids[$ancestorId] = true;
            }
        }

        $groupIds = array_map(intval(...), array_keys($ids));

        if (null !== $userId) {
            $this->readerGroupIds[$userId] = $groupIds;
        }

        return $groupIds;
    }

    /**
     * The groups an author may post on: their own perimeter, or all of it for a manager.
     *
     * @return list<int>
     */
    public function writableGroupIds(?User $user, bool $isManager): array
    {
        return $isManager ? $this->eligibleIds() : $this->readerGroupIds($user);
    }

    public function find(int $groupId): ?Group
    {
        foreach ($this->allGroups() as $group) {
            if ($group->getId() === $groupId) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The chain from the root down to the group, the group last - the breadcrumb of a page de
     * garde ("Accueil › Base documentaire › BTS SIO › SIO 2 — A").
     *
     * @return list<Group>
     */
    public function pathTo(Group $group): array
    {
        $path = $group->getAncestors();
        $path[] = $group;

        return $path;
    }

    /** @return list<Group> */
    private function allGroups(): array
    {
        return $this->groups ??= $this->groupRepository->findActiveOrderedByType();
    }

    /** @return array<int, int|null> */
    private function parentMap(): array
    {
        if (null !== $this->parentMap) {
            return $this->parentMap;
        }

        $map = [];

        foreach ($this->allGroups() as $group) {
            $id = $group->getId();

            if (null !== $id) {
                $map[$id] = $group->getParent()?->getId();
            }
        }

        return $this->parentMap = $map;
    }
}
