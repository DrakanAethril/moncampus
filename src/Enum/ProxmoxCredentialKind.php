<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How MonCampus authenticates against a declared Proxmox host.
 *
 * Both modes end in the same encrypted column; what differs is what the secret *is* and what the
 * client does with it. An API token is offered first because it is revocable on its own, never
 * expires, and needs neither a session ticket nor the CSRF header Proxmox demands on every non-GET
 * of a ticket-authenticated call.
 */
enum ProxmoxCredentialKind: string
{
    /** The account's own password. Traded for a two-hour ticket at /access/ticket. */
    case Password = 'password';

    /** A `user@realm!tokenid` token whose secret goes straight into the Authorization header. */
    case ApiToken = 'api_token';

    public function labelKey(): string
    {
        return match ($this) {
            self::Password => 'proxmoxCredentialPasswordLabel',
            self::ApiToken => 'proxmoxCredentialApiTokenLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::Password => 'proxmoxCredentialPasswordDescription',
            self::ApiToken => 'proxmoxCredentialApiTokenDescription',
        };
    }
}
