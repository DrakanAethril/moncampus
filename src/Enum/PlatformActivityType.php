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

    /**
     * An admin set, changed or cleared one person's derogation on one feature (the « Fonctionnalités »
     * block of the annuaire card). Recorded because it is an act on somebody else's account that
     * leaves no other trace: choosing « Par défaut » deletes the row, so the table itself cannot say
     * a decision was ever taken.
     */
    case FeatureOverrideChanged = 'feature_override_changed';

    /**
     * Somebody connected Claude to their account (the consent screen of the connector). Recorded
     * because from then on a third-party application acts in their name, and the grant it received
     * is the first thing to look at when something was created that nobody remembers creating.
     */
    case ClaudeConnectorAuthorized = 'claude_connector_authorized';

    /** A connection of the Claude connector was closed from « Mon profil ». */
    case ClaudeConnectorRevoked = 'claude_connector_revoked';

    /**
     * Claude created something through the connector - a quiz, a séquence, a file, an evaluation.
     * The payload names the tool, the kind of object and its id, so the trace leads to it.
     */
    case ClaudeConnectorContentCreated = 'claude_connector_content_created';

    /** Placeholder disponible : %user%. */
    public function messageKey(): string
    {
        return match ($this) {
            self::LoginPassword => 'platformActivityLoginPasswordText',
            self::LoginMagicLink => 'platformActivityLoginMagicLinkText',
            self::SchoolMailUnlinkedDeleted => 'platformActivitySchoolMailUnlinkedDeletedText',
            self::ProxmoxGuestCreated => 'platformActivityProxmoxGuestCreatedText',
            self::ProxmoxPostInstallRun => 'platformActivityProxmoxPostInstallRunText',
            self::FeatureOverrideChanged => 'platformActivityFeatureOverrideChangedText',
            self::ClaudeConnectorAuthorized => 'platformActivityClaudeConnectorAuthorizedText',
            self::ClaudeConnectorRevoked => 'platformActivityClaudeConnectorRevokedText',
            self::ClaudeConnectorContentCreated => 'platformActivityClaudeConnectorContentCreatedText',
        };
    }
}
