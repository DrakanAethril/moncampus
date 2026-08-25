<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three things this application asks the directory to do to an account that already exists -
 * as opposed to `ldap_manage_user`, which asks it to create one.
 *
 * An enumeration and not the string constants App\Entity\LdapManageUser::ACTION_TYPES uses, because
 * the three cases genuinely differ: only one carries a second login, and each one has its own
 * consequence on this side once the directory confirms. A `match` over an enumeration is what makes
 * a fourth case impossible to forget - the compiler asks the question, instead of a `switch` with a
 * default nobody reads.
 *
 * The values are the contract with the consumer script (manage/manage_account.php in the
 * Scripts/samba/ldap project): they are what `action_type` holds and what its own `match` switches
 * on. Renaming one means renaming it there, and rewriting history rows.
 */
enum LdapAccountAction: string
{
    /** samba-tool user disable. The platform is already closed by the time this is queued. */
    case Disable = 'account_disable';

    /** samba-tool user enable. Written for this feature - the directory had no way back. */
    case Enable = 'account_enable';

    /** samba-tool user rename, plus the move of the personal folder on the file server. */
    case LoginChange = 'login_change';

    /**
     * Only a rename carries a second login, and it is the only case where `new_login` may be
     * anything but NULL.
     */
    public function requiresNewLogin(): bool
    {
        return self::LoginChange === $this;
    }

    /**
     * Whether the application has anything to rewrite once the directory has confirmed. Both
     * deactivation cases have already been applied at the click (App\Entity\User::$inactiveDate),
     * which is the asymmetry the whole feature is built on: closing the platform waits for nobody,
     * renaming waits for the directory.
     */
    public function appliesOnConfirmation(): bool
    {
        return self::LoginChange === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Disable => 'ldapAccountActionDisableLabel',
            self::Enable => 'ldapAccountActionEnableLabel',
            self::LoginChange => 'ldapAccountActionLoginChangeLabel',
        };
    }
}
