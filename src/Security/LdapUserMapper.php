<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Maps an LDAP inetOrgPerson entry's attributes (mail, cn) and groupOfNames membership
 * onto a local User entity - shared by the login-time JIT provisioning (LdapAuthenticator)
 * and the bulk directory sync (LdapUserSyncer).
 */
class LdapUserMapper
{
    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly string $ldapBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
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
        $groups = $this->ldap->query($this->ldapBaseDn, \sprintf('(&(objectClass=groupOfNames)(member=%s))', $escapedDn))->execute();

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
