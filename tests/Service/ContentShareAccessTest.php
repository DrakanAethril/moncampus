<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ContentShareScope;
use App\Service\ContentShareAccess;
use App\Service\GroupHierarchy;
use PHPUnit\Framework\TestCase;

/**
 * The whole rule of design/validated/content-sharing-between-teachers.md's "Access model", over
 * primitives: three scopes, the group hierarchy's descendants, revocation, and the roles that are
 * never readers.
 *
 * The hierarchy used throughout is the campus one, and its shape is what matters:
 *
 *     1 campus
 *       2 BTS SIO
 *         3 SIO 1
 *         4 SIO 2
 *       5 BTS MCO
 */
class ContentShareAccessTest extends TestCase
{
    private const array HIERARCHY = [1 => null, 2 => 1, 3 => 2, 4 => 2, 5 => 1];

    private ContentShareAccess $access;

    protected function setUp(): void
    {
        $this->access = new ContentShareAccess(new GroupHierarchy());
    }

    public function testNamedUserReads(): void
    {
        $this->assertTrue($this->allows(ContentShareScope::Users, shareUserIds: [7, 9], readerId: 9));
    }

    public function testUserNotNamedDoesNotRead(): void
    {
        $this->assertFalse($this->allows(ContentShareScope::Users, shareUserIds: [7, 9], readerId: 12));
    }

    public function testMemberOfTheNamedGroupReads(): void
    {
        $this->assertTrue($this->allows(ContentShareScope::Group, shareGroupIds: [2], readerGroupIds: [2]));
    }

    public function testMemberOfADescendantGroupReads(): void
    {
        // Shared with « BTS SIO »; the reader only carries « SIO 2 », one level down.
        $this->assertTrue($this->allows(ContentShareScope::Group, shareGroupIds: [2], readerGroupIds: [4]));
    }

    public function testMemberOfASiblingGroupDoesNotRead(): void
    {
        $this->assertFalse($this->allows(ContentShareScope::Group, shareGroupIds: [2], readerGroupIds: [5]));
    }

    public function testAncestorOfTheNamedGroupDoesNotRead(): void
    {
        // Sharing goes down, never up: « BTS SIO » does not reach somebody who only carries
        // « campus ».
        $this->assertFalse($this->allows(ContentShareScope::Group, shareGroupIds: [2], readerGroupIds: [1]));
    }

    public function testCatalogReachesEveryTeacher(): void
    {
        $this->assertTrue($this->allows(ContentShareScope::Catalog, readerGroupIds: []));
    }

    public function testRevokedShareIsReadByNobody(): void
    {
        $this->assertFalse($this->allows(
            ContentShareScope::Users,
            shareUserIds: [9],
            readerId: 9,
            revokedAt: new \DateTimeImmutable('2026-08-01'),
        ));
    }

    public function testOwnerStillReadsARevokedShare(): void
    {
        // Their own item: the share is what the *others* read through, and revoking it is not the
        // author locking themselves out.
        $this->assertTrue($this->allows(
            ContentShareScope::Users,
            shareUserIds: [9],
            readerId: 3,
            revokedAt: new \DateTimeImmutable('2026-08-01'),
        ));
    }

    public function testStudentNamedInAShareStillDoesNotRead(): void
    {
        $this->assertFalse($this->allows(
            ContentShareScope::Users,
            shareUserIds: [9],
            readerId: 9,
            readerRoles: ['ROLE_USER', 'ROLE_STUDENT'],
        ));
    }

    public function testTutorDoesNotReadTheCatalog(): void
    {
        $this->assertFalse($this->allows(
            ContentShareScope::Catalog,
            readerRoles: ['ROLE_USER', 'ROLE_TUTOR'],
        ));
    }

    public function testExternalDoesNotReadTheCatalog(): void
    {
        $this->assertFalse($this->allows(
            ContentShareScope::Catalog,
            readerRoles: ['ROLE_USER', 'ROLE_EXTERNAL'],
        ));
    }

    public function testStaffReadsTheCatalog(): void
    {
        $this->assertTrue($this->allows(
            ContentShareScope::Catalog,
            readerRoles: ['ROLE_USER', 'ROLE_STAFF'],
        ));
    }

    /** Only the owner shares - deliberately no staff bypass, unlike SequenceTemplateVoter::EDIT. */
    public function testOnlyTheOwnerMayShare(): void
    {
        $this->assertTrue($this->access->mayShare(3, 3, ['ROLE_USER', 'ROLE_TEACHER']));
        $this->assertFalse($this->access->mayShare(3, 8, ['ROLE_USER', 'ROLE_ADMIN']));
        $this->assertFalse($this->access->mayShare(3, 3, ['ROLE_USER', 'ROLE_STUDENT']));
    }

    public function testResolvedGroupIdsCarryTheWholeBranch(): void
    {
        $this->assertSame([2, 3, 4], $this->sorted($this->access->resolvedGroupIds([2], self::HIERARCHY)));
        $this->assertSame([1, 2, 3, 4, 5], $this->sorted($this->access->resolvedGroupIds([1], self::HIERARCHY)));
        $this->assertSame([], $this->access->resolvedGroupIds([], self::HIERARCHY));
    }

    public function testResolvedGroupIdsDoNotRepeatAnOverlappingPick(): void
    {
        $this->assertSame([2, 3, 4], $this->sorted($this->access->resolvedGroupIds([2, 3], self::HIERARCHY)));
    }

    /**
     * The count the picker states **before** the submit. The « campus » root is the case that
     * matters: picking it shares with everybody while looking like a small gesture.
     */
    public function testResolvedMemberCount(): void
    {
        $roleByGroupId = [1 => 'ROLE_CAMPUS', 2 => 'ROLE_SIO', 3 => 'ROLE_SIO1', 4 => 'ROLE_SIO2', 5 => 'ROLE_MCO'];
        $candidates = [
            ['id' => 10, 'roles' => ['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS', 'ROLE_SIO', 'ROLE_SIO1']],
            ['id' => 11, 'roles' => ['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS', 'ROLE_SIO', 'ROLE_SIO2']],
            ['id' => 12, 'roles' => ['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS', 'ROLE_MCO']],
            // A student of SIO 1 is a member of the group and is never a reader of a share.
            ['id' => 13, 'roles' => ['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS', 'ROLE_SIO', 'ROLE_SIO1']],
        ];

        $count = fn (array $groupIds): int => \count($this->access->resolveMemberIds($groupIds, self::HIERARCHY, $roleByGroupId, $candidates));

        $this->assertSame(2, $count([2]), 'BTS SIO and its two classes');
        $this->assertSame(1, $count([3]));
        $this->assertSame(3, $count([1]), 'the campus root reaches the whole establishment');
        $this->assertSame(0, $count([]));
    }

    public function testResolvedMemberCountCountsEachPersonOnce(): void
    {
        $roleByGroupId = [2 => 'ROLE_SIO', 3 => 'ROLE_SIO1'];
        $candidates = [['id' => 10, 'roles' => ['ROLE_TEACHER', 'ROLE_SIO', 'ROLE_SIO1']]];

        $this->assertSame([10], $this->access->resolveMemberIds([2, 3], self::HIERARCHY, $roleByGroupId, $candidates));
    }

    /**
     * @param list<int>    $shareUserIds
     * @param list<int>    $shareGroupIds
     * @param list<int>    $readerGroupIds
     * @param list<string> $readerRoles
     */
    private function allows(
        ContentShareScope $scope,
        array $shareUserIds = [],
        array $shareGroupIds = [],
        int $readerId = 9,
        array $readerGroupIds = [],
        array $readerRoles = ['ROLE_USER', 'ROLE_TEACHER'],
        ?\DateTimeImmutable $revokedAt = null,
    ): bool {
        return $this->access->allows(
            $scope,
            $revokedAt,
            3,
            $shareUserIds,
            $shareGroupIds,
            $readerId,
            $readerGroupIds,
            $readerRoles,
            self::HIERARCHY,
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
