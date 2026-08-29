<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserLoginHistory;
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
        private readonly LoginEmailResolver $loginEmailResolver,
        private readonly AccountStatusChecker $accountStatusChecker,
        private readonly string $ldapBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
        private readonly string $ldapUsernameAttribute,
        private readonly UserLoginHistory $loginHistory,
    ) {
    }

    public function loadOrCreateUser(string $username): User
    {
        // Closing the platform needs nobody's permission, so a deactivated account is turned away
        // before a single packet leaves for the directory. AccountStatusChecker would refuse it
        // anyway - it is the firewalls' user_checker, and it runs on this passport a moment later -
        // but only *after* the LDAP search below has already run, since a user checker sees a user
        // and there is no user until one has been loaded. This is the same rule, read from the same
        // place, applied one step earlier on the path that carries every login of the day.
        $known = $this->userRepository->findOneBy(['username' => $username]);

        if (null !== $known) {
            $this->accountStatusChecker->checkPreAuth($known);
        }

        $entry = $this->findLdapEntry($username);

        // The login field doubles as a username-or-email field: if what was typed isn't itself an
        // LDAP uid, try resolving it as a confirmed contact address (LoginEmailResolver - and see
        // its docblock for why the school address is not one), then retry under that account's real
        // username. The LDAP bind right below still enforces the real password; this only changes
        // which uid it binds as.
        if (null === $entry) {
            $resolved = $this->loginEmailResolver->resolve($username);

            if (null !== $resolved) {
                $username = $resolved->getUsername();
                $entry = $this->findLdapEntry($username);
            }
        }

        if (null === $entry) {
            throw new UserNotFoundException(\sprintf('No LDAP entry found for username "%s".', $username));
        }

        $existing = $this->userRepository->findOneBy(['username' => $username]);
        $user = $existing ?? new User($username);
        $this->ldapUserMapper->apply($user, $entry);

        $this->entityManager->persist($user);

        if (null === $existing) {
            // Just-in-time provisioning is a creation like any other, and the ledger starts at the
            // account's first login rather than at its first rename. Only on creation: an existing
            // account already has its row, and asking on every single sign-in would buy a query per
            // login for nothing.
            $this->loginHistory->record($user, $username);
        }

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
}
