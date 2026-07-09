<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Maps an LDAP person entry's attributes (mail, cn) and group membership onto a local User
 * entity - shared by the login-time JIT provisioning (LdapAuthenticator) and the bulk directory
 * sync (LdapUserSyncer). Group objectClass is configurable (LDAP_GROUP_OBJECT_CLASS) since it
 * differs by directory flavor - groupOfNames (OpenLDAP/RFC2307) vs group (Active Directory/Samba).
 */
class LdapUserMapper
{
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly string $ldapBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
        private readonly string $ldapGroupObjectClass,
    ) {
    }

    public function apply(User $user, Entry $entry): void
    {
        $user->setEmail(($entry->getAttribute('mail') ?? [])[0] ?? null);
        $user->setDisplayName(($entry->getAttribute('cn') ?? [])[0] ?? null);
        $user->setRoles($this->resolveRoles($entry));
    }

    /** @return list<string> */
    private function resolveRoles(Entry $entry): array
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $escapedDn = $this->ldap->escape($entry->getDn(), '', LdapInterface::ESCAPE_FILTER);
        $groups = $this->ldap->query($this->ldapBaseDn, \sprintf('(&(objectClass=%s)(member=%s))', $this->ldapGroupObjectClass, $escapedDn))->execute();

        $roles = [];
        foreach ($groups as $group) {
            $cn = ($group->getAttribute('cn') ?? [])[0] ?? null;
            if (null !== $cn) {
                $roles[] = 'ROLE_'.strtoupper($cn);
            }
        }

        return $roles;
    }
}
