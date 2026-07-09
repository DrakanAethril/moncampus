<?php

namespace App\Service;

use App\Entity\Group;
use App\Entity\User;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Bulk-mirrors every LDAP group into the local App\Entity\Group table. App\Security\LdapUserMapper
 * already does this opportunistically for any group a logging-in user belongs to, but a group
 * with no members who've logged in yet would never get mirrored that way - this manual sync
 * (Settings > Groups' "sync now" action) covers that gap by pulling every LDAP group regardless
 * of membership. Existing Group rows are never updated or removed, same "only add the missing
 * ones" contract as LdapUserSyncer.
 */
class LdapGroupSyncer
{
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupRepository $groupRepository,
        private readonly string $ldapBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
        private readonly string $ldapGroupObjectClass,
    ) {
    }

    public function sync(User $actingUser): int
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $entries = $this->ldap->query($this->ldapBaseDn, \sprintf('(objectClass=%s)', $this->ldapGroupObjectClass))->execute();

        $existingLdapCns = array_flip(array_filter(array_map(
            static fn (Group $group): ?string => $group->getLdapCn(),
            $this->groupRepository->findAll(),
        )));

        $createdCount = 0;
        foreach ($entries as $entry) {
            $cn = ($entry->getAttribute('cn') ?? [])[0] ?? null;

            if (null === $cn || isset($existingLdapCns[$cn])) {
                continue;
            }

            $group = new Group($cn, 'ROLE_'.strtoupper($cn), $cn);
            $group->setCreatedBy($actingUser);
            $this->entityManager->persist($group);
            $existingLdapCns[$cn] = true;
            ++$createdCount;
        }

        $this->entityManager->flush();

        return $createdCount;
    }
}
