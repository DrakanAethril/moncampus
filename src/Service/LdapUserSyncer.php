<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LdapUserMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Bulk-creates local User rows for LDAP accounts that don't have one yet (e.g. accounts
 * created directly in LDAP rather than through this app, or from before this app existed).
 * Existing User rows are never updated or removed - this only adds the missing ones.
 */
class LdapUserSyncer
{
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly LdapUserMapper $ldapUserMapper,
        private readonly string $ldapBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
    ) {
    }

    public function sync(): int
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $entries = $this->ldap->query($this->ldapBaseDn, '(&(objectClass=inetOrgPerson)(uid=*))')->execute();

        $existingUsernames = array_flip(array_map(
            static fn (User $user): string => $user->getUsername(),
            $this->userRepository->findAll(),
        ));

        $createdCount = 0;
        foreach ($entries as $entry) {
            $username = ($entry->getAttribute('uid') ?? [])[0] ?? null;

            if (null === $username || isset($existingUsernames[$username])) {
                continue;
            }

            $user = new User($username);
            $this->ldapUserMapper->apply($user, $entry);
            $this->entityManager->persist($user);
            ++$createdCount;
        }

        $this->entityManager->flush();

        return $createdCount;
    }
}
