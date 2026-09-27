<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Entity\User;
use App\Security\PlatformPasswordCheck;

/**
 * The directory, as far as « is this the establishment password? » goes - the tests cannot bind to
 * one. Whatever is declared here is the establishment password of every account.
 */
final class FakePlatformPasswordCheck implements PlatformPasswordCheck
{
    public const string PLATFORM_PASSWORD = 'Etablissement#2026';

    public function isPlatformPassword(User $user, string $password): bool
    {
        return self::PLATFORM_PASSWORD === $password;
    }
}
