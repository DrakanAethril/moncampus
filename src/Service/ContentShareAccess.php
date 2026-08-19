<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ContentShareScope;

/**
 * The single answer to "who reads what a colleague shared" -
 * design/validated/content-sharing-between-teachers.md, "Access model".
 *
 * It exists for the reason App\Service\HelpAccess exists: five subjects and four screens asking the
 * question separately is four chances to disagree. And like App\Service\DocumentationAccess,
 * everything here is primitives - ids, an enum, a date, a plain parent map - so the rule is testable
 * without an entity graph and answerable from a repository row as easily as from a hydrated share.
 *
 * Three rules, and no fourth:
 *
 * 1. **`ROLE_STUDENT`, `ROLE_TUTOR` and `ROLE_EXTERNAL` are never readers**, under any scope. The
 *    two latter are already out of every message audience platform-wide; this is the same rule, said
 *    once, here, and not in a template.
 * 2. **A revoked share is read by nobody but its owner.** Revoking removes access and only access -
 *    what the colleague already duplicated is theirs and is not touched.
 * 3. **`group` reaches the named groups and everything below them**, never above. Sharing with
 *    « BTS SIO » reaches its classes; it does not reach somebody who only carries « campus ».
 *
 * **Who may share is the owner, and there is deliberately no staff bypass** - unlike
 * SequenceTemplateVoter::EDIT. The precedent for that asymmetry is
 * StructureAccessChecker::isProgramReferentTeacher(), which is also not bypassed: sharing is an act
 * of authorship, and handing a colleague's work around on their behalf is not a gesture this screen
 * offers. A staff member shares their own items like anyone else.
 */
class ContentShareAccess
{
    /**
     * Who may ever read a share, whatever its scope - the same list FileLibraryVoter calls
     * "who has a library", and for the same reason: a share lands in a library.
     *
     * @var list<string>
     */
    public const array READER_ROLES = ['ROLE_TEACHER', 'ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    public function __construct(private readonly GroupHierarchy $hierarchy)
    {
    }

    /** @param list<string> $roles */
    public function isReader(array $roles): bool
    {
        return [] !== array_intersect(self::READER_ROLES, $roles);
    }

    /**
     * The owner of the item, and nobody else. Not staff-bypassed - see the class docblock.
     *
     * @param list<string> $roles
     */
    public function mayShare(int $ownerId, int $userId, array $roles): bool
    {
        return $ownerId === $userId && $this->isReader($roles);
    }

    /**
     * @param list<int>            $shareUserIds
     * @param list<int>            $shareGroupIds
     * @param list<int>            $readerGroupIds the groups whose role the reader carries
     * @param list<string>         $readerRoles
     * @param array<int, int|null> $parentByGroupId
     */
    public function allows(
        ContentShareScope $scope,
        ?\DateTimeImmutable $revokedAt,
        int $ownerId,
        array $shareUserIds,
        array $shareGroupIds,
        int $readerId,
        array $readerGroupIds,
        array $readerRoles,
        array $parentByGroupId,
    ): bool {
        if (!$this->isReader($readerRoles)) {
            return false;
        }

        if ($ownerId === $readerId) {
            return true;
        }

        if (null !== $revokedAt) {
            return false;
        }

        return match ($scope) {
            ContentShareScope::Users => \in_array($readerId, $shareUserIds, true),
            ContentShareScope::Group => [] !== array_intersect($this->resolvedGroupIds($shareGroupIds, $parentByGroupId), $readerGroupIds),
            ContentShareScope::Catalog => true,
        };
    }

    /**
     * The named groups **and everything below them** - the one walk of the hierarchy this feature
     * does, shared by the reader check above and by the count the picker states before the submit.
     *
     * @param list<int>            $shareGroupIds
     * @param array<int, int|null> $parentByGroupId
     *
     * @return list<int>
     */
    public function resolvedGroupIds(array $shareGroupIds, array $parentByGroupId): array
    {
        $resolved = [];

        foreach ($shareGroupIds as $groupId) {
            foreach ($this->hierarchy->branchIds($groupId, $parentByGroupId) as $branchId) {
                $resolved[$branchId] = true;
            }
        }

        return array_map(intval(...), array_keys($resolved));
    }

    /**
     * Who a `group` pick actually reaches - « ce partage sera visible de 87 personnes », **measured,
     * not estimated**.
     *
     * The hierarchy's root is « campus », so picking it shares with everybody while looking like a
     * small gesture: the picker has to be able to say so before the submit, which is what this
     * answers. Members who could never read a share (students, tutors, external accounts) are not
     * counted - the sentence says "visible de", not "membres de".
     *
     * @param list<int>                                $shareGroupIds
     * @param array<int, int|null>                     $parentByGroupId
     * @param array<int, string>                       $roleByGroupId  the role each group grants
     * @param list<array{id: int, roles: list<string>}> $candidates    the accounts to measure against
     *
     * @return list<int>
     */
    public function resolveMemberIds(array $shareGroupIds, array $parentByGroupId, array $roleByGroupId, array $candidates): array
    {
        $roles = [];

        foreach ($this->resolvedGroupIds($shareGroupIds, $parentByGroupId) as $groupId) {
            $role = $roleByGroupId[$groupId] ?? null;

            if (null !== $role) {
                $roles[] = $role;
            }
        }

        if ([] === $roles) {
            return [];
        }

        $memberIds = [];

        foreach ($candidates as $candidate) {
            if ($this->isReader($candidate['roles']) && [] !== array_intersect($roles, $candidate['roles'])) {
                $memberIds[$candidate['id']] = true;
            }
        }

        return array_map(intval(...), array_keys($memberIds));
    }
}
