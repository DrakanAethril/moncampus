<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\FileLibraryNodeRepository;
use App\Service\ByteSize;
use App\Service\FileLibraryQuota;
use PHPUnit\Framework\TestCase;

/**
 * What a library is allowed to weigh (design/validated/file-library.md, "Quota").
 *
 * The case worth pinning is the last one: an admin lowering a quota below current usage deletes
 * nothing, the bar reads **over 100 %**, and uploads are refused until the teacher frees space. A
 * percentage capped at 100 there would hide the very thing they have to act on.
 */
class FileLibraryQuotaTest extends TestCase
{
    private const int GIGABYTE = 1024 ** 3;

    public function testAnAccountWithNoOverrideGetsThePlatformDefault(): void
    {
        $quota = $this->quota(usedBytes: 0);

        // Null is not zero: it is "whatever the platform currently says", which is what lets the
        // default be raised later for everyone who was never overridden.
        self::assertSame(self::GIGABYTE, $quota->limitFor($this->user(null)));
        self::assertSame(self::GIGABYTE, $quota->defaultBytes());
    }

    public function testAnOverrideWinsOverTheDefault(): void
    {
        $quota = $this->quota(usedBytes: 0);

        self::assertSame(2 * self::GIGABYTE, $quota->limitFor($this->user(2 * self::GIGABYTE)));
    }

    public function testUsageAndRemainingAreMeasuredAgainstTheLimit(): void
    {
        $quota = $this->quota(usedBytes: 312 * 1024 ** 2);
        $user = $this->user(null);

        self::assertSame(312 * 1024 ** 2, $quota->usedBytes($user));
        // 30 and not the mockup's 31: the drawing rounds 312/1000, this counts binary units, which
        // is what the teacher's own file manager says and what the limit is expressed in.
        self::assertSame(30, $quota->usedPercent($user));
        self::assertSame(self::GIGABYTE - 312 * 1024 ** 2, $quota->remainingBytes($user));
        self::assertSame('green', $quota->level($user));
    }

    public function testTheBarTurnsAmberThenRed(): void
    {
        self::assertSame('amber', $this->quota(usedBytes: (int) (0.8 * self::GIGABYTE))->level($this->user(null)));
        self::assertSame('red', $this->quota(usedBytes: (int) (0.95 * self::GIGABYTE))->level($this->user(null)));
    }

    public function testAFileLargerThanWhatIsLeftIsRefusedWithItsNumbers(): void
    {
        $quota = $this->quota(usedBytes: self::GIGABYTE - 240 * 1024 ** 2);
        $user = $this->user(null);

        self::assertTrue($quota->accepts($user, 100 * 1024 ** 2));
        self::assertFalse($quota->accepts($user, 380 * 1024 ** 2));

        $refusal = $quota->refusal($user, 380 * 1024 ** 2);
        self::assertSame('fileLibraryQuotaExceededMessage', $refusal['key']);
        self::assertSame('240 Mo', $refusal['parameters']['%remaining%']);
        self::assertSame('380 Mo', $refusal['parameters']['%size%']);
    }

    public function testALoweredQuotaLeavesTheLibraryOverItsLimitRatherThanDeletingAnything(): void
    {
        // 1,18 Go stored, quota lowered to 1 Go: nothing is deleted, the bar reads 118 % and every
        // upload is refused until space is freed.
        $quota = $this->quota(usedBytes: (int) (1.18 * self::GIGABYTE));
        $user = $this->user(self::GIGABYTE);

        self::assertSame(118, $quota->usedPercent($user));
        self::assertSame('red', $quota->level($user));
        self::assertSame(0, $quota->remainingBytes($user));
        self::assertFalse($quota->accepts($user, 1));
    }

    public function testTheDefaultIsReadFromConfigurationAndNotFrozenInCode(): void
    {
        $quota = new FileLibraryQuota($this->nodes(0), '2 Go');

        self::assertSame(2 * self::GIGABYTE, $quota->defaultBytes());
        // And an unreadable setting falls back to the documented 1 Go rather than to zero, which
        // would refuse every upload on the platform.
        self::assertSame(self::GIGABYTE, (new FileLibraryQuota($this->nodes(0), 'nonsense'))->defaultBytes());
    }

    public function testByteSizeReadsWhatAnAdminTypesAndWritesWhatAScreenShows(): void
    {
        self::assertSame(2 * self::GIGABYTE, ByteSize::parse('2G'));
        self::assertSame(2 * self::GIGABYTE, ByteSize::parse('2 Go'));
        self::assertSame(2500 * 1024 ** 2, ByteSize::parse('2500 Mo'));
        // A French keyboard writes 1,5 - read rather than refused.
        self::assertSame((int) round(1.5 * self::GIGABYTE), ByteSize::parse('1,5 Go'));
        self::assertNull(ByteSize::parse(''));
        self::assertNull(ByteSize::parse('beaucoup'));

        self::assertSame('312 Mo', ByteSize::format(312 * 1024 ** 2));
        self::assertSame('1 Go', ByteSize::format(self::GIGABYTE));
        self::assertSame('940 Ko', ByteSize::format(940 * 1024));
    }

    private function quota(int $usedBytes): FileLibraryQuota
    {
        return new FileLibraryQuota($this->nodes($usedBytes), '1G');
    }

    private function nodes(int $usedBytes): FileLibraryNodeRepository
    {
        $nodes = $this->createStub(FileLibraryNodeRepository::class);
        $nodes->method('usedBytes')->willReturn($usedBytes);

        return $nodes;
    }

    private function user(?int $quotaBytes): User
    {
        return (new User('quota.tester'))->setFileLibraryQuotaBytes($quotaBytes);
    }
}
