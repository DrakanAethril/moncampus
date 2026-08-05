<?php

namespace App\Enum;

/**
 * Where an App\Entity\EmailAlias comes from - which decides both the format rules it must follow
 * and what a user interface is allowed to do with it.
 *
 * The distinction is not documentary: reception being catch-all, a local part taken is taken for
 * the whole school. An alias typed by hand is therefore a sending identity conjured up on the
 * school's domain, which an alias derived from civil status or from the directory is not.
 */
enum EmailAliasOrigin: string
{
    /** Built from civil status (`firstname.lastname`) by App\Service\StudentMailAddressGenerator. */
    case Generated = 'generated';

    /** The student's LDAP login (`croux`), taken as is - the only case without a dot. */
    case Login = 'login';

    /** Typed by a human. The only case bound by the dot rule, and the only administrable one. */
    case Manual = 'manual';

    /**
     * An alias derived from civil status or from the directory cannot be edited from the app: it
     * follows its source. Only the manual one is created, disabled and deleted by hand.
     */
    public function isManageable(): bool
    {
        return self::Manual === $this;
    }

    /**
     * The dot rule only applies to the manual case. The login is exempt precisely because it is not
     * typed: it mirrors the directory's identifier, which never has a dot.
     */
    public function requiresDot(): bool
    {
        return self::Manual === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Generated => 'emailAliasOriginGeneratedLabel',
            self::Login => 'emailAliasOriginLoginLabel',
            self::Manual => 'emailAliasOriginManualLabel',
        };
    }
}
