<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Authenticated symmetric encryption of the secrets the Proxmox console holds (an API token, an
 * account password, the platform SSH private key). XSalsa20-Poly1305 through ext-sodium, which is
 * already loaded in the container.
 *
 * The envelope is `v1.<nonce base64>.<ciphertext base64>`, versioned so a future key rotation or
 * algorithm change can read the old rows while writing the new format. A fresh nonce per write is
 * what makes two seals of the same plaintext differ, so nothing about a secret leaks from the
 * column itself.
 *
 * Why not the AES_ENCRYPT() of App\Entity\LdapManagePassword, the only encryption precedent in this
 * repository: the plaintext would travel inside the SQL statement (so into general_log and the slow
 * query log), MySQL 8 defaults block_encryption_mode to aes-128-ecb - deterministic, no IV - and
 * the result is not authenticated, so a tampered row decrypts to garbage rather than failing.
 *
 * The key is its own environment variable (PROXMOX_SECRET_KEY), never APP_SECRET: rotating the
 * application secret must not make every declared host unreachable.
 */
class SecretBox
{
    private const string ENVELOPE_VERSION = 'v1';

    private readonly string $key;

    /**
     * @param string $proxmoxSecretKey 32 raw bytes, base64-encoded
     *
     * @throws SecretBoxException when the key is missing or is not 32 bytes once decoded - at
     *                            construction rather than at the first call, so a misconfigured
     *                            deployment is visible on the screen that lists hosts instead of
     *                            surfacing as a 500 the day somebody tests a connection
     */
    public function __construct(#[\SensitiveParameter] string $proxmoxSecretKey)
    {
        if ('' === $proxmoxSecretKey) {
            throw new SecretBoxException('PROXMOX_SECRET_KEY is empty: Proxmox secrets cannot be read or written.');
        }

        $decoded = base64_decode($proxmoxSecretKey, true);

        if (false === $decoded) {
            throw new SecretBoxException('PROXMOX_SECRET_KEY is not valid base64.');
        }

        if (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($decoded)) {
            throw new SecretBoxException(\sprintf('PROXMOX_SECRET_KEY must decode to %d bytes, got %d.', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, \strlen($decoded)));
        }

        $this->key = $decoded;
    }

    /**
     * @return non-empty-string the envelope to store, safe to log in the sense that it reveals
     *                          nothing, but never meant to be shown either
     */
    public function seal(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return \sprintf(
            '%s.%s.%s',
            self::ENVELOPE_VERSION,
            base64_encode($nonce),
            base64_encode(sodium_crypto_secretbox($plain, $nonce, $this->key)),
        );
    }

    /**
     * @throws SecretBoxException on an unknown version, a malformed envelope, or a failed
     *                            authentication tag - the three are one failure for the caller:
     *                            this value cannot be trusted
     */
    public function open(string $sealed): string
    {
        $parts = explode('.', $sealed);

        if (3 !== \count($parts) || self::ENVELOPE_VERSION !== $parts[0]) {
            throw new SecretBoxException('Sealed secret is not a recognised envelope.');
        }

        $nonce = base64_decode($parts[1], true);
        $cipher = base64_decode($parts[2], true);

        if (false === $nonce || false === $cipher || \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== \strlen($nonce)) {
            throw new SecretBoxException('Sealed secret is malformed.');
        }

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (false === $plain) {
            throw new SecretBoxException('Sealed secret failed authentication: wrong key, or the row was altered.');
        }

        return $plain;
    }
}
