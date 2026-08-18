<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\Group;
use App\Entity\Progression;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Repository\ContentShareRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The database half of the sharing audience - App\Service\ContentShareAccess holds the rule, this
 * feeds it.
 *
 * The split is App\Service\DocumentationAccess / App\Service\DocumentationPerimeter's, and for the
 * same reason: the rule stays testable over plain ids while one place owns the queries the rule
 * needs - the group hierarchy, the role each group grants, and who carries them.
 *
 * Memoised per request and reset between them, for the reason App\Service\AudienceResolver spells
 * out: under FrankenPHP worker mode the instance outlives the request, and here that would mean
 * answering one reader's audience with another's.
 */
class ContentShareAudience implements ResetInterface
{
    /** @var list<Group>|null */
    private ?array $groups = null;

    /** @var array<int, int|null>|null */
    private ?array $parentMap = null;

    /** @var array<int, list<int>> */
    private array $readerGroupIds = [];

    /** @var list<array{id: int, roles: list<string>}>|null */
    private ?array $candidates = null;

    public function __construct(
        private readonly GroupRepository $groupRepository,
        private readonly UserRepository $userRepository,
        private readonly ContentShareRepository $shares,
        private readonly ContentShareAccess $access,
        private readonly GroupHierarchy $hierarchy,
    ) {
    }

    public function reset(): void
    {
        $this->groups = null;
        $this->parentMap = null;
        $this->readerGroupIds = [];
        $this->candidates = null;
    }

    /** @return array<int, int|null> */
    public function parentMap(): array
    {
        return $this->parentMap ??= $this->groupRepository->findParentMap();
    }

    /**
     * The groups whose role this person carries. Walked **downwards** from the share's own groups
     * afterwards, so nothing is expanded here - a reader of « SIO 2 » is a member of « SIO 2 » and
     * of nothing else.
     *
     * @return list<int>
     */
    public function readerGroupIds(User $reader): array
    {
        $readerId = $reader->getId();

        if (null !== $readerId && isset($this->readerGroupIds[$readerId])) {
            return $this->readerGroupIds[$readerId];
        }

        $roles = $reader->getRoles();
        $ids = [];

        foreach ($this->allGroups() as $group) {
            $id = $group->getId();

            if (null !== $id && \in_array($group->getRole(), $roles, true)) {
                $ids[] = $id;
            }
        }

        if (null !== $readerId) {
            $this->readerGroupIds[$readerId] = $ids;
        }

        return $ids;
    }

    public function allows(ContentShare $share, User $reader): bool
    {
        return $this->access->allows(
            $share->getScope(),
            $share->getRevokedAt(),
            (int) $share->getOwner()->getId(),
            $share->getUserIds(),
            $share->getGroupIds(),
            (int) $reader->getId(),
            $this->readerGroupIds($reader),
            $reader->getRoles(),
            $this->parentMap(),
        );
    }

    /**
     * Is this item readable by that person through **some** share of it? The one question the VIEW
     * attribute of every voter delegates here, so five screens cannot answer it five ways.
     */
    public function isSharedWith(SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode|Progression $subject, User $reader): bool
    {
        foreach ($this->shares->findForSubject($subject) as $share) {
            if ($this->allows($share, $reader)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<ContentShare> $shares
     *
     * @return list<ContentShare>
     */
    public function filterReadable(iterable $shares, User $reader): array
    {
        $readable = [];

        foreach ($shares as $share) {
            if ($this->allows($share, $reader)) {
                $readable[] = $share;
            }
        }

        return $readable;
    }

    /**
     * « Ce partage sera visible de 87 personnes » - measured, never estimated, and stated **before**
     * the submit. The hierarchy's root is « campus », so a small-looking pick can reach everybody.
     *
     * @param list<int> $groupIds
     */
    public function memberCount(array $groupIds): int
    {
        return \count($this->access->resolveMemberIds($groupIds, $this->parentMap(), $this->roleByGroupId(), $this->candidates()));
    }

    /**
     * The picker's list: the groups that take part in the hierarchy, indented as a tree, so
     * « BTS SIO » and « SIO 2 — A » read as the levels they are.
     *
     * @return list<array{group: Group, depth: int}>
     */
    public function pickableGroups(): array
    {
        $byId = [];
        $rows = [];

        foreach ($this->allGroups() as $group) {
            $id = $group->getId();

            if (null === $id) {
                continue;
            }

            $byId[$id] = $group;
            $rows[] = ['id' => $id, 'parentId' => $group->getParent()?->getId()];
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

    /** @return array<int, string> */
    private function roleByGroupId(): array
    {
        $roles = [];

        foreach ($this->allGroups() as $group) {
            $id = $group->getId();

            if (null !== $id) {
                $roles[$id] = $group->getRole();
            }
        }

        return $roles;
    }

    /**
     * Everyone who could read a share at all. Narrowed to the reader roles here rather than in the
     * count itself, so the roster query is the smallest one that answers the question.
     *
     * @return list<array{id: int, roles: list<string>}>
     */
    private function candidates(): array
    {
        if (null !== $this->candidates) {
            return $this->candidates;
        }

        $candidates = [];

        foreach ($this->userRepository->findActiveMatchingAnyRole(ContentShareAccess::READER_ROLES) as $user) {
            $id = $user->getId();

            if (null !== $id) {
                $candidates[] = ['id' => $id, 'roles' => $user->getRoles()];
            }
        }

        return $this->candidates = $candidates;
    }

    /** @return list<Group> */
    private function allGroups(): array
    {
        return $this->groups ??= $this->groupRepository->findActiveOrderedByType();
    }
}
