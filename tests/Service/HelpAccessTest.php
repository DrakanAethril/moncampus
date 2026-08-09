<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\HelpAudience;
use App\Service\HelpAccess;
use PHPUnit\Framework\TestCase;

/**
 * Who reads what in the help.
 *
 * The rule is small enough to state in one line - an admin reads everything, anyone else reads
 * what names one of their audiences - and consequential enough to pin: an article addressed to
 * staff must not become readable by a teacher because a section above it happened to be wider.
 */
class HelpAccessTest extends TestCase
{
    public function testAdminReadsEverythingIncludingUnpublishedAndAudienceLess(): void
    {
        $access = new HelpAccess();

        self::assertTrue($access->allows([HelpAudience::Student], [], true));
        self::assertTrue($access->allows([], [], true));
    }

    public function testReaderNeedsOneMatchingAudience(): void
    {
        $access = new HelpAccess();
        $teacher = [HelpAudience::Teacher];

        self::assertTrue($access->allows([HelpAudience::Teacher], $teacher, false));
        self::assertTrue($access->allows([HelpAudience::Teacher, HelpAudience::Staff], $teacher, false));
        self::assertFalse($access->allows([HelpAudience::Staff], $teacher, false));
    }

    public function testAnEntryAddressedToNobodyReachesNobodyButAnAdmin(): void
    {
        $access = new HelpAccess();

        self::assertFalse($access->allows([], [HelpAudience::Teacher], false));
        self::assertFalse($access->allows([], [HelpAudience::Staff, HelpAudience::Teacher], false));
    }

    public function testSomeoneWithNoAudienceAtAllReadsNothing(): void
    {
        $access = new HelpAccess();

        self::assertFalse($access->allows([HelpAudience::Teacher], [], false));
    }

    public function testStaffAndStaffLeadShareOneAudience(): void
    {
        self::assertSame([HelpAudience::Staff], HelpAudience::fromRoles(['ROLE_USER', 'ROLE_STAFF']));
        self::assertSame([HelpAudience::Staff], HelpAudience::fromRoles(['ROLE_USER', 'ROLE_STAFF-LEAD']));
        self::assertSame(
            [HelpAudience::Teacher, HelpAudience::Staff],
            HelpAudience::fromRoles(['ROLE_TEACHER', 'ROLE_STAFF-LEAD']),
        );
    }

    public function testAdminAloneHoldsNoAudienceOfItsOwn(): void
    {
        // Deliberate: ROLE_ADMIN is not an audience, it is a bypass. An admin who also teaches
        // still reads the teacher articles as a teacher would - through the bypass, not through a
        // fifth audience nobody would ever address content to.
        self::assertSame([], HelpAudience::fromRoles(['ROLE_USER', 'ROLE_ADMIN']));
    }
}
