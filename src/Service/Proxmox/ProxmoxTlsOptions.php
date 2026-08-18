<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Enum\ProxmoxTlsMode;

/**
 * Turns a host's TLS choice into the HttpClient options that enforce it.
 *
 * Two of the four modes need care:
 *
 *   - `ca` is the recommended one, and Symfony's HttpClient has no "here is the PEM" option: it
 *     wants `cafile`, a path. The PEM is therefore written once into the cache directory under a
 *     content-addressed name, so editing a host's CA writes a new file rather than mutating the one
 *     an in-flight request is reading, and an unchanged CA reuses the same file for ever.
 *   - `pin` maps to `peer_fingerprint => ['pin-sha256' => …]`, and that is the *public key* (SPKI)
 *     digest. CurlHttpClient accepts nothing else (vendor/symfony/http-client/CurlHttpClient.php),
 *     while NativeHttpClient accepts only certificate fingerprints and refuses `pin-sha256`
 *     outright - ext-curl is loaded in this image, so curl is what runs. The SHA-256 Proxmox shows
 *     in its own interface is the certificate's, which is why nothing here invites anyone to paste
 *     it: the connection test reads the live pin and offers it.
 *
 * Written on primitives rather than on the entity so the "test this form before saving it" path can
 * use it with values that have no row yet.
 */
class ProxmoxTlsOptions
{
    public function __construct(private readonly string $proxmoxCaDir)
    {
    }

    /**
     * @return array<string, mixed> options to merge into an HttpClient request
     *
     * @throws ProxmoxUnavailableException when a mode is chosen without the material it needs, or
     *                                     when the CA cannot be written to disk - failing loudly
     *                                     rather than silently falling back to the system bundle,
     *                                     which would verify against the wrong trust store
     */
    public function build(ProxmoxTlsMode $mode, ?string $caPem, ?string $pinSha256): array
    {
        return match ($mode) {
            ProxmoxTlsMode::System => [],
            ProxmoxTlsMode::Ca => ['cafile' => $this->materialiseCa($caPem)],
            ProxmoxTlsMode::Pin => ['peer_fingerprint' => ['pin-sha256' => [$this->requirePin($pinSha256)]]],
            // Both flags, not just verify_peer: curl still checks the name against the certificate
            // otherwise, and an IP-addressed host with a self-signed certificate fails on the host
            // check alone - which would make this mode look broken rather than permissive.
            ProxmoxTlsMode::Insecure => ['verify_peer' => false, 'verify_host' => false],
        };
    }

    private function requirePin(?string $pinSha256): string
    {
        if (null === $pinSha256 || '' === trim($pinSha256)) {
            throw new ProxmoxUnavailableException('TLS mode "pin" was selected but no public-key pin is set.');
        }

        return trim($pinSha256);
    }

    private function materialiseCa(?string $caPem): string
    {
        if (null === $caPem || '' === trim($caPem)) {
            throw new ProxmoxUnavailableException('TLS mode "ca" was selected but no CA certificate is set.');
        }

        $normalised = trim($caPem)."\n";
        $path = \sprintf('%s/%s.pem', rtrim($this->proxmoxCaDir, '/'), hash('sha256', $normalised));

        if (is_file($path)) {
            return $path;
        }

        if (!is_dir($this->proxmoxCaDir) && !@mkdir($this->proxmoxCaDir, 0o775, true) && !is_dir($this->proxmoxCaDir)) {
            throw new ProxmoxUnavailableException('Could not create the directory holding Proxmox CA certificates.');
        }

        // Written under a temporary name then renamed: another worker may be reading this path at
        // the same moment, and rename() is the atomic half of the operation.
        $temporary = $path.'.'.bin2hex(random_bytes(6));

        if (false === @file_put_contents($temporary, $normalised) || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new ProxmoxUnavailableException('Could not write the Proxmox CA certificate to disk.');
        }

        return $path;
    }
}
