<?php

namespace App\Security;

use App\Entity\Group;
use App\Entity\User;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Maps an LDAP person entry's attributes (mail, givenName, sn) and group membership onto a local
 * User entity - shared by the login-time JIT provisioning (LdapAuthenticator) and the bulk
 * directory sync (LdapUserSyncer). Group objectClass is configurable (LDAP_GROUP_OBJECT_CLASS)
 * since it differs by directory flavor - groupOfNames (OpenLDAP/RFC2307) vs group (Active
 * Directory/Samba).
 *
 * firstname/lastname are read from givenName/sn rather than cn: the prod Samba/AD consumer script
 * (create_user.sh, in the separate ldap-manage project) creates every account with
 * --use-username-as-cn, so cn is always just the login there - givenName/sn are the two
 * attributes it reliably sets to the real name instead.
 *
 * Every LDAP group encountered here is also opportunistically mirrored into the local
 * App\Entity\Group table (see App\Service\LdapGroupSyncer for the equivalent manual bulk sync,
 * needed for a group nobody's a member of yet) - same "mirror into a local row on first sight"
 * JIT pattern this class already uses for the User itself.
 *
 * Group membership is searched under $ldapGroupBaseDn when set, else falls back to the same
 * $ldapBaseDn used for the user lookup itself - see LdapGroupSyncer's docblock for why this is
 * needed at all (a Samba 4 AD DC with users and groups in separate OUs).
 */
class LdapUserMapper
{
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupRepository $groupRepository,
        private readonly string $ldapBaseDn,
        private readonly string $ldapGroupBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
        private readonly string $ldapGroupObjectClass,
    ) {
    }

    public function apply(User $user, Entry $entry): void
    {
        $user->setEmail(($entry->getAttribute('mail') ?? [])[0] ?? null);
        $user->setFirstname(($entry->getAttribute('givenName') ?? [])[0] ?? null);
        $user->setLastname(($entry->getAttribute('sn') ?? [])[0] ?? null);
        $user->setRoles($this->resolveRoles($entry, $user));
    }

    /** @return list<string> */
    private function resolveRoles(Entry $entry, User $actingUser): array
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $escapedDn = $this->ldap->escape($entry->getDn(), '', LdapInterface::ESCAPE_FILTER);
        $groups = $this->ldap->query($this->resolveGroupBaseDn(), \sprintf('(&(objectClass=%s)(member=%s))', $this->ldapGroupObjectClass, $escapedDn))->execute();

        $roles = [];
        foreach ($groups as $group) {
            $cn = ($group->getAttribute('cn') ?? [])[0] ?? null;

            if (null === $cn) {
                continue;
            }

            $roles[] = $this->mirrorGroup($cn, $actingUser)->getRole();
        }

        return $roles;
    }

    // Upserts by ldapCn - not persisted/flushed here (the caller flushes once, after applying
    // everything for this login/sync entry), so an as-yet-unpersisted $actingUser can still be
    // set as createdBy: Doctrine orders the eventual INSERTs correctly from the FK dependency.
    private function mirrorGroup(string $cn, User $actingUser): Group
    {
        $existing = $this->groupRepository->findOneByLdapCn($cn);

        if (null !== $existing) {
            return $existing;
        }

        $group = new Group($cn, 'ROLE_'.strtoupper($cn), $cn);
        $group->setCreatedBy($actingUser);
        $this->entityManager->persist($group);

        return $group;
    }

    private function resolveGroupBaseDn(): string
    {
        return '' !== $this->ldapGroupBaseDn ? $this->ldapGroupBaseDn : $this->ldapBaseDn;
    }
}
