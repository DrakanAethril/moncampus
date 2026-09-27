<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Ldap\Exception\LdapException;

/**
 * Answers App\Security\PlatformPasswordCheck the only way it can be answered, since no password of
 * the directory is kept anywhere here: by trying it. A bind as the person with the candidate
 * password that succeeds means it **is** their establishment password.
 *
 * A directory that cannot be reached is an exception, never a « no ».
 */
final readonly class LdapPlatformPasswordCheck implements PlatformPasswordCheck
{
    public function __construct(private LdapCredentialsVerifier $verifier)
    {
    }

    public function isPlatformPassword(User $user, string $password): bool
    {
        try {
            return $this->verifier->verifyPassword($password, $user);
        } catch (LdapException $exception) {
            throw new PlatformPasswordCheckUnavailable('The directory could not be reached.', 0, $exception);
        }
    }
}
