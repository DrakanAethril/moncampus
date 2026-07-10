<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LdapUserMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Bulk-mirrors LDAP accounts into the local User table: creates rows for accounts that don't
 * have one yet (e.g. accounts created directly in LDAP rather than through this app, or from
 * before this app existed), and refreshes email/firstname/lastname/roles for ones that already
 * do - the same LDAP-owned fields LdapUserMapper::apply() already overwrites on every login, just
 * without needing to wait for that user's next login (e.g. a name correction in LDAP would
 * otherwise stay stale here until they happen to log in again). Never removes a row, even if the
 * matching LDAP account no longer exists - same "additive only" caution as every other syncer in
 * this app, just extended to cover refreshing what's already known rather than only what's new.
 * User objectClass/username attribute are configurable since they differ by directory flavor -
 * inetOrgPerson/uid (OpenLDAP/RFC2307) vs user/sAMAccountName (Active Directory/Samba).
 *
 * Searched under $ldapUserBaseDn when set, else falling back to $ldapBaseDn - narrowing this
 * matters on a Samba 4 AD DC, where computer and service accounts are also objectClass=user
 * entries, so an unscoped search from the whole domain silently pulls those in as if they were
 * real people too.
 */
class LdapUserSyncer
{
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly LdapUserMapper $ldapUserMapper,
        private readonly string $ldapBaseDn,
        private readonly string $ldapUserBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
        private readonly string $ldapUserObjectClass,
        private readonly string $ldapUsernameAttribute,
    ) {
    }

    /** @return array{created: int, updated: int} */
    public function sync(): array
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $entries = $this->ldap->query($this->resolveUserBaseDn(), \sprintf('(&(objectClass=%s)(%s=*))', $this->ldapUserObjectClass, $this->ldapUsernameAttribute))->execute();

        $existingUsersByUsername = [];
        foreach ($this->userRepository->findAll() as $user) {
            $existingUsersByUsername[$user->getUsername()] = $user;
        }

        $createdCount = 0;
        $updatedCount = 0;
        foreach ($entries as $entry) {
            $username = ($entry->getAttribute($this->ldapUsernameAttribute) ?? [])[0] ?? null;

            if (null === $username) {
                continue;
            }

            $existingUser = $existingUsersByUsername[$username] ?? null;

            if (null !== $existingUser) {
                $this->ldapUserMapper->apply($existingUser, $entry);
                ++$updatedCount;

                continue;
            }

            $user = new User($username);
            $this->ldapUserMapper->apply($user, $entry);
            $this->entityManager->persist($user);
            ++$createdCount;
        }

        $this->entityManager->flush();

        return ['created' => $createdCount, 'updated' => $updatedCount];
    }

    private function resolveUserBaseDn(): string
    {
        return '' !== $this->ldapUserBaseDn ? $this->ldapUserBaseDn : $this->ldapBaseDn;
    }
}
