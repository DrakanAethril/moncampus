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
 * Two addresses are looked up, in that order of authority:
 *
 *  1. User::$email, the LDAP `mail` attribute mirrored at every login by LdapUserMapper - the
 *     school address, written by the directory itself, so there is nothing here to prove;
 *  2. User::$contactEmail, but only once confirmed (User::isContactEmailVerified()) - a personal
 *     address the account holder typed, only worth trusting after the mailed link was followed.
 *
 * An address matching several accounts resolves to none: unlike $contactEmail, the mirrored LDAP
 * address carries no uniqueness constraint, and picking one of two accounts is worse than asking
 * for the username. Inactivated accounts never resolve either.
 *
 * This deliberately applies no role restriction, unlike MagicLoginService::isEligible(), and the
 * difference is not an oversight: a magic link *is* the whole proof of identity, whereas resolving
 * an address here only decides which uid the LDAP bind runs against - the real password is still
 * required right after (LdapCredentialsVerifier::verifyPassword()). Excluding ROLE_ADMIN from a
 * path that proves nothing on its own would only stop administrators from typing their own email.
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

        return $this->resolveByLdapEmail($email) ?? $this->resolveByContactEmail($email);
    }

    private function resolveByLdapEmail(string $email): ?User
    {
        $candidates = array_values(array_filter(
            $this->userRepository->findByLdapEmail($email),
            static fn (User $user): bool => null === $user->getInactiveDate(),
        ));

        return 1 === \count($candidates) ? $candidates[0] : null;
    }

    private function resolveByContactEmail(string $email): ?User
    {
        $user = $this->userRepository->findOneBy(['contactEmail' => $email]);

        if (null === $user || null !== $user->getInactiveDate() || !$user->isContactEmailVerified()) {
            return null;
        }

        return $user;
    }
}
