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

    /**
     * A machine was created on a Proxmox host (the console at /infrastructure). Logged here as well
     * as in App\Entity\ProxmoxOperation because the two answer different questions: the operations
     * log is the story of one hypervisor, this is the story of what people did on the platform.
     */
    case ProxmoxGuestCreated = 'proxmox_guest_created';

    /**
     * A post-installation script was run inside a machine. This one is the reason the pair exists:
     * it is arbitrary command execution as root, and while it grants an administrator no power they
     * did not already hold, an act of that shape gets recorded where acts are recorded.
     */
    case ProxmoxPostInstallRun = 'proxmox_post_install_run';

    /** Placeholder disponible : %user%. */
    public function messageKey(): string
    {
        return match ($this) {
            self::LoginPassword => 'platformActivityLoginPasswordText',
            self::LoginMagicLink => 'platformActivityLoginMagicLinkText',
            self::SchoolMailUnlinkedDeleted => 'platformActivitySchoolMailUnlinkedDeletedText',
            self::ProxmoxGuestCreated => 'platformActivityProxmoxGuestCreatedText',
            self::ProxmoxPostInstallRun => 'platformActivityProxmoxPostInstallRunText',
        };
    }
}
