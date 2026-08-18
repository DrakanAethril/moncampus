<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the TLS certificate a Proxmox host presents is verified. Four explicit modes, and none of
 * them is a silent "don't check" - a freshly installed Proxmox serves a self-signed certificate,
 * and the honest answer to that is to import its cluster CA, not to turn verification off.
 *
 * Pin is the awkward one and is named for what it actually holds: Symfony's CurlHttpClient accepts
 * `peer_fingerprint` only as `pin-sha256`, which is a hash of the *public key* (SPKI), while the
 * SHA-256 fingerprint Proxmox prints in its own interface is a hash of the *certificate*. The two
 * are not interchangeable, so the field is labelled "public-key pin" everywhere and the value is
 * read off the live connection rather than typed by hand.
 */
enum ProxmoxTlsMode: string
{
    /** The system CA bundle - right when the host has an ACME/public certificate. */
    case System = 'system';

    /** The cluster's own CA, pasted as PEM (`cat /etc/pve/pve-root-ca.pem`). The recommended mode. */
    case Ca = 'ca';

    /** A base64 SPKI SHA-256 public-key pin, offered by the connection test rather than typed. */
    case Pin = 'pin';

    /** No verification at all. Permanently badged in red and named in the operations log. */
    case Insecure = 'insecure';

    public function labelKey(): string
    {
        return match ($this) {
            self::System => 'proxmoxTlsSystemLabel',
            self::Ca => 'proxmoxTlsCaLabel',
            self::Pin => 'proxmoxTlsPinLabel',
            self::Insecure => 'proxmoxTlsInsecureLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::System => 'proxmoxTlsSystemDescription',
            self::Ca => 'proxmoxTlsCaDescription',
            self::Pin => 'proxmoxTlsPinDescription',
            self::Insecure => 'proxmoxTlsInsecureDescription',
        };
    }

    public function isVerified(): bool
    {
        return self::Insecure !== $this;
    }
}
