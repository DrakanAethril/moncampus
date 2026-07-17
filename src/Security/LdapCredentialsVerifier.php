<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\LdapInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * LDAP bind/search logic shared by every entry point that authenticates a user against LDAP -
 * originally only LdapAuthenticator (the web login form), now also ApiLdapAuthenticator (the
 * stateless JSON login the Flutter app calls) - so both stay in lockstep on how a credential is
 * verified and a User row is JIT-provisioned, instead of drifting apart as separate copies.
 */
class LdapCredentialsVerifier
{
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly LdapUserMapper $ldapUserMapper,
        private readonly string $ldapBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
        private readonly string $ldapUsernameAttribute,
    ) {
    }

    public function loadOrCreateUser(string $username): User
    {
        $entry = $this->findLdapEntry($username);

        // The login field doubles as a username-or-contact-email field: if what was typed looks
        // like an email and isn't itself an LDAP uid, try resolving it to the account whose
        // verified contact email matches, then retry under that account's real username - the
        // LDAP bind right below still enforces the real password, this only changes which uid it
        // binds as.
        if (null === $entry && $this->looksLikeEmail($username)) {
            $resolvedUsername = $this->resolveUsernameFromContactEmail($username);

            if (null !== $resolvedUsername) {
                $username = $resolvedUsername;
                $entry = $this->findLdapEntry($username);
            }
        }

        if (null === $entry) {
            throw new UserNotFoundException(\sprintf('No LDAP entry found for username "%s".', $username));
        }

        $user = $this->userRepository->findOneBy(['username' => $username]) ?? new User($username);
        $this->ldapUserMapper->apply($user, $entry);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function verifyPassword(string $password, User $user): bool
    {
        $entry = $this->findLdapEntry($user->getUserIdentifier());

        if (null === $entry) {
            return false;
        }

        try {
            $this->ldap->bind($entry->getDn(), $password);
        } catch (ConnectionException) {
            return false;
        }

        return true;
    }

    private function findLdapEntry(string $username): ?Entry
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $escapedUsername = $this->ldap->escape($username, '', LdapInterface::ESCAPE_FILTER);
        $results = $this->ldap->query($this->ldapBaseDn, \sprintf('(%s=%s)', $this->ldapUsernameAttribute, $escapedUsername))->execute();

        return $results[0] ?? null;
    }

    private function looksLikeEmail(string $value): bool
    {
        return false !== filter_var($value, \FILTER_VALIDATE_EMAIL);
    }

    // Same eligibility bar as the magic-link login (MagicLoginService::isEligible): the contact
    // email must be verified (never resolve off an unconfirmed address) and the account must not
    // be inactive or ROLE_ADMIN - admins always authenticate under their real LDAP uid.
    private function resolveUsernameFromContactEmail(string $contactEmail): ?string
    {
        $user = $this->userRepository->findOneBy(['contactEmail' => $contactEmail]);

        if (null === $user
            || null !== $user->getInactiveDate()
            || !$user->isContactEmailVerified()
            || \in_array('ROLE_ADMIN', $user->getRoles(), true)
        ) {
            return null;
        }

        return $user->getUsername();
    }
}
