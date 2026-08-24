<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Translates an email address typed into a login field back to the account it belongs to, so the
 * field accepts either an LDAP username or an address - people know their own email far better
 * than the login the directory gave them.
 *
 * One address, and one only: User::$contactEmail, once confirmed (User::isContactEmailVerified()).
 *
 * The school address mirrored from the LDAP `mail` attribute onto User::$email is deliberately
 * *not* accepted, although it is the one the directory itself wrote. Three reasons, and they
 * compound: it is derivable from a person's name rather than chosen, so it turns "guess the login"
 * into "guess nothing at all"; it carries no uniqueness constraint, so two entries may legitimately
 * share one; and nobody ever claimed it - $contactEmail is an address its owner typed and then
 * proved they read, which is precisely what makes it worth resolving on.
 *
 * Inactivated accounts never resolve.
 *
 * This applies no role restriction, unlike MagicLoginService::isEligible(), and the difference is
 * not an oversight: a magic link *is* the whole proof of identity, whereas resolving an address
 * here only decides which uid the LDAP bind runs against - the real password is still required
 * right after (LdapCredentialsVerifier::verifyPassword()). Excluding ROLE_ADMIN from a path that
 * proves nothing on its own would only stop administrators from typing their own address.
 */
class LoginEmailResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function resolve(string $email): ?User
    {
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = $this->userRepository->findOneBy(['contactEmail' => $email]);

        if (null === $user || null !== $user->getInactiveDate() || !$user->isContactEmailVerified()) {
            return null;
        }

        return $user;
    }
}
