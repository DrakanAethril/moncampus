<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * What a host's TLS certificate says, read from the live connection. Two digests, and the whole
 * point of this object is that they are *named apart*:
 *
 *   - `fingerprint` hashes the certificate. It is what `pvenode cert info` prints and what the
 *     Proxmox interface shows.
 *   - `publicKeyPin` hashes the SubjectPublicKeyInfo. It is what `peer_fingerprint => pin-sha256`
 *     wants, and the only thing CurlHttpClient will accept.
 *
 * They are never interchangeable, and pasting one where the other is expected fails with a TLS
 * error that names neither. Showing both, labelled, at the moment the choice is made is the remedy.
 */
final readonly class ProxmoxCertificate
{
    public function __construct(
        /** Colon-separated uppercase hex, as Proxmox prints it. */
        public string $fingerprint,
        /** Base64, as `pin-sha256` wants it. */
        public string $publicKeyPin,
        public string $subject,
        public string $issuer,
        public ?\DateTimeImmutable $validUntil,
        public bool $selfSigned,
    ) {
    }
}
