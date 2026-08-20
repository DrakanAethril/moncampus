<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\GuestAuthorizedKeys;
use PHPUnit\Framework\TestCase;

/**
 * Who gets into a machine this application creates.
 *
 * Written against primitives rather than entities because the rule is the whole of it: three
 * conditions and an order. Everything around it - reading the rows, merging LDAP roles with the
 * groups set by hand - is plumbing that the repository and User already answer.
 *
 * The order is not cosmetic. The platform key comes first because it is the one MonCampus itself
 * uses, and it must be installed even when nobody else's is: losing it means losing the machine.
 */
class GuestAuthorizedKeysTest extends TestCase
{
    private const string PLATFORM = 'ssh-ed25519 AAAAplatform';

    public function testThePlatformKeyComesFirstAndAlone(): void
    {
        self::assertSame([self::PLATFORM], GuestAuthorizedKeys::select(self::PLATFORM, []));
    }

    public function testAnActiveAdministratorsKeysAreInstalled(): void
    {
        $keys = GuestAuthorizedKeys::select(self::PLATFORM, [
            $this->candidate('ssh-ed25519 AAAAmarie', ['ROLE_ADMIN', 'ROLE_USER']),
        ]);

        self::assertSame([self::PLATFORM, 'ssh-ed25519 AAAAmarie'], $keys);
    }

    /** Several machines each, which is the ordinary case and the reason keys are a list. */
    public function testEveryKeyOfEveryAdministratorIsInstalled(): void
    {
        $keys = GuestAuthorizedKeys::select(self::PLATFORM, [
            $this->candidate('ssh-ed25519 AAAAmarie-portable', ['ROLE_ADMIN']),
            $this->candidate('ssh-ed25519 AAAAmarie-bureau', ['ROLE_ADMIN']),
            $this->candidate('ssh-rsa AAAApaul', ['ROLE_ADMIN']),
        ]);

        self::assertCount(4, $keys);
    }

    public function testSomebodyWhoIsNotAnAdministratorIsLeftOut(): void
    {
        $keys = GuestAuthorizedKeys::select(self::PLATFORM, [
            $this->candidate('ssh-ed25519 AAAAteacher', ['ROLE_TEACHER', 'ROLE_USER']),
            $this->candidate('ssh-ed25519 AAAAstaff', ['ROLE_STAFF']),
        ]);

        self::assertSame([self::PLATFORM], $keys);
    }

    /**
     * The one that matters on the day somebody leaves: the account is deactivated, and the next
     * machine created stops carrying their key. Nobody has to remember to come and delete rows.
     */
    public function testADeactivatedAdministratorIsLeftOut(): void
    {
        $keys = GuestAuthorizedKeys::select(self::PLATFORM, [
            $this->candidate('ssh-ed25519 AAAAgone', ['ROLE_ADMIN'], active: false),
        ]);

        self::assertSame([self::PLATFORM], $keys);
    }

    /**
     * A repeated line in authorized_keys is harmless to sshd and confusing to read, and the case is
     * real: two administrators sharing a machine, or somebody adding the platform key by hand.
     */
    public function testTheSameKeyIsNotInstalledTwice(): void
    {
        $keys = GuestAuthorizedKeys::select(self::PLATFORM, [
            $this->candidate('ssh-ed25519 AAAAmarie', ['ROLE_ADMIN']),
            $this->candidate('ssh-ed25519 AAAAmarie', ['ROLE_ADMIN']),
            $this->candidate(self::PLATFORM, ['ROLE_ADMIN']),
        ]);

        self::assertSame([self::PLATFORM, 'ssh-ed25519 AAAAmarie'], $keys);
    }

    /**
     * No platform key yet is a real state - it is generated on demand - and it must not swallow the
     * administrators' keys with it.
     */
    public function testAdministratorKeysStillGoOutWhenThereIsNoPlatformKey(): void
    {
        self::assertSame(['ssh-ed25519 AAAAmarie'], GuestAuthorizedKeys::select(null, [
            $this->candidate('ssh-ed25519 AAAAmarie', ['ROLE_ADMIN']),
        ]));
    }

    public function testNothingToInstallIsAnEmptyList(): void
    {
        self::assertSame([], GuestAuthorizedKeys::select(null, []));
    }

    /**
     * @param list<string> $roles
     *
     * @return array{roles: list<string>, active: bool, key: string}
     */
    private function candidate(string $key, array $roles, bool $active = true): array
    {
        return ['roles' => $roles, 'active' => $active, 'key' => $key];
    }
}
