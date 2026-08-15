<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a row of App\Entity\PlatformActivity tells - the log outside the UFA. Same extension mechanics
 * as App\Enum\UfaActivityType: a case, a key, a call to the recorder.
 *
 * Deliberately limited to successful logins for now: failed logins are not recorded (a product
 * decision - they would bear on non-existent usernames and would change the nature of the table), and
 * neither is logging out.
 */
enum PlatformActivityType: string
{
    case LoginPassword = 'login_password';
    case LoginMagicLink = 'login_magic_link';

    /**
     * An admin removed an unlinked school mail from the platform (screen 5a). Logged because it is
     * somebody else's incoming mail being erased from the app - the raw `.eml` stays on S3, and the
     * payload keeps the keys that make it findable again.
     */
    case SchoolMailUnlinkedDeleted = 'school_mail_unlinked_deleted';

    /** Placeholder disponible : %user%. */
    public function messageKey(): string
    {
        return match ($this) {
            self::LoginPassword => 'platformActivityLoginPasswordText',
            self::LoginMagicLink => 'platformActivityLoginMagicLinkText',
            self::SchoolMailUnlinkedDeleted => 'platformActivitySchoolMailUnlinkedDeletedText',
        };
    }
}
