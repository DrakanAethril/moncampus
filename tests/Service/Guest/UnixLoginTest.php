<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\UnixLogin;
use PHPUnit\Framework\TestCase;

/**
 * What a Unix login may be.
 *
 * This check earns its own test because of who now depends on it: a student's login on a machine is
 * their platform username, taken as it stands, and App\Service\Guest\GuestAccountSyncer skips an
 * account whose login it refuses rather than raising. The batch asks this question before building
 * anything precisely so that refusal is loud - so what counts as valid is not a detail.
 */
class UnixLoginTest extends TestCase
{
    public function testAnOrdinaryLoginIsAccepted(): void
    {
        self::assertTrue((new UnixLogin())->isValid('sio1-001'));
        self::assertTrue((new UnixLogin())->isValid('mdupont'));
    }

    public function testUppercaseAndDotsAreRefused(): void
    {
        // Both are ordinary in a school directory, and neither is a Unix login.
        self::assertFalse((new UnixLogin())->isValid('MDupont'));
        self::assertFalse((new UnixLogin())->isValid('marie.dupont'));
        self::assertFalse((new UnixLogin())->isValid('marie.dupont@school.fr'));
    }

    public function testALoginMustStartWithALetter(): void
    {
        self::assertFalse((new UnixLogin())->isValid('12345'));
        self::assertFalse((new UnixLogin())->isValid('-marie'));
    }

    public function testAReservedNameIsRefused(): void
    {
        self::assertFalse((new UnixLogin())->isValid('root'));
        self::assertFalse((new UnixLogin())->isValid('admin'));
    }

    public function testEmptyAndOverlongAreRefused(): void
    {
        self::assertFalse((new UnixLogin())->isValid(''));
        self::assertFalse((new UnixLogin())->isValid(str_repeat('a', 33)));
        self::assertTrue((new UnixLogin())->isValid(str_repeat('a', 32)));
    }
}
