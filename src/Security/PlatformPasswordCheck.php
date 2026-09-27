<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * « Is this the person's establishment password? » - asked of every password chosen for an external
 * service, which must never be it.
 *
 * An interface because the only honest answer is the directory's (App\Security\
 * LdapPlatformPasswordCheck, a bind), and the tests cannot reach a directory.
 */
interface PlatformPasswordCheck
{
    /**
     * @throws PlatformPasswordCheckUnavailable when the directory cannot be asked - the caller must
     *                                          then refuse rather than assume the answer is no
     */
    public function isPlatformPassword(User $user, string $password): bool;
}
