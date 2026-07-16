<?php

namespace App\Service;

use App\Entity\LdapManageUser;

/**
 * Derives the initial User::$roles for an account created via
 * App\Controller\DirectoryUserController::new(), from the same userType/userGroups the staff
 * member just picked - using the exact same 'ROLE_'.strtoupper($cn) convention
 * App\Security\LdapUserMapper::mirrorGroup() uses when deriving roles from real LDAP group
 * membership. Matching it here is what keeps this only a *placeholder*: once the account's first
 * real LDAP login happens, LdapUserMapper overwrites $roles wholesale from actual group
 * membership - if the two conventions ever diverged, staff/students would see their permissions
 * visibly change at that point instead of it being a no-op.
 */
class LdapManageUserRoleResolver
{
    /** @return list<string> */
    public function resolve(LdapManageUser $ldapManageUser): array
    {
        $roles = [$this->toRole($ldapManageUser->getUserType())];

        foreach (array_filter(explode('|', $ldapManageUser->getUserGroups())) as $group) {
            $roles[] = $this->toRole($group);
        }

        // ROLE_ADMIN can't actually be selected through this form today (see
        // LdapManageUserType::availableSecondaryGroups() and LdapManageUser::USER_TYPES, both of
        // which already exclude it) - stripped anyway as defense in depth, since a User row is
        // created directly from this without ever going through LDAP's own admin-group bind.
        $roles = array_values(array_diff(array_unique($roles), ['ROLE_ADMIN']));

        return $roles;
    }

    private function toRole(string $cn): string
    {
        return 'ROLE_'.mb_strtoupper($cn);
    }
}
