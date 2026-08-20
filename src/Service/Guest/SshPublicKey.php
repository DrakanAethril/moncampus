<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * One line of an `authorized_keys` file, read as a value.
 *
 * It exists because the alternative - keeping what was pasted as a string - fails silently at the
 * far end. A malformed line in a machine's authorized_keys is not an error anywhere: sshd skips it
 * and carries on, so the administrator simply cannot log in, with nothing in any log of this
 * application to say why. The refusal has to happen where the key is typed.
 *
 * **The announced type is checked against the body**, which is the only part of the parsing that is
 * not obvious. An OpenSSH blob repeats its own algorithm name as its first field, length-prefixed;
 * `ssh-rsa <an ed25519 body>` therefore has a first word that looks right and a body that
 * contradicts it. sshd refuses such a line, and a form that only split on spaces would have stored
 * it.
 *
 * Parsed with the string functions rather than phpseclib: this reads the outer envelope and never
 * the key material, so there is no cryptography to get wrong, and it keeps the fingerprint - which
 * an administrator compares with their own `ssh-keygen -lf` - a plain SHA-256 of the same bytes.
 */
final class SshPublicKey
{
    private function __construct(
        public readonly string $type,
        public readonly string $body,
        public readonly ?string $comment,
    ) {
    }

    /** @throws \InvalidArgumentException when the line is not a usable public key */
    public static function parse(string $line): self
    {
        $trimmed = trim($line);

        // Three fields at most: the third is the comment, which keeps its own spaces because
        // ssh-keygen writes an unquoted machine name into it.
        $parts = explode(' ', $trimmed, 3);

        if (\count($parts) < 2) {
            throw new \InvalidArgumentException('A public key is a type followed by its body.');
        }

        [$type, $body] = $parts;
        $comment = isset($parts[2]) && '' !== trim($parts[2]) ? trim($parts[2]) : null;

        $blob = base64_decode($body, true);

        if (false === $blob || '' === $blob) {
            throw new \InvalidArgumentException('The body of a public key is base64.');
        }

        if ($type !== self::announcedType($blob)) {
            throw new \InvalidArgumentException('The key announces a type its body does not carry.');
        }

        return new self($type, $body, $comment);
    }

    /** The same reading, for the callers that have somewhere to show a refusal rather than raise. */
    public static function tryParse(string $line): ?self
    {
        try {
            return self::parse($line);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The `SHA256:…` form OpenSSH prints, so that what this screen shows can be compared with what
     * `ssh-keygen -lf` says on the administrator's own machine. Base64 without its padding, which
     * is the part that is easy to get wrong.
     */
    public function fingerprint(): string
    {
        $blob = base64_decode($this->body, true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', false !== $blob ? $blob : '', true)), '=');
    }

    /**
     * What goes into the database and into a machine, comment dropped.
     *
     * The comment is the one part an administrator writes freely, and it would travel into the
     * authorized_keys of every machine created afterwards. The label carried alongside the key
     * names it on the screen instead, where it stays.
     */
    public function toStorage(): string
    {
        return $this->type.' '.$this->body;
    }

    /**
     * The algorithm name the blob carries, read from its first length-prefixed field.
     *
     * Null rather than an exception on anything unreadable: the caller's next line compares it with
     * the announced type, and "unreadable" and "does not match" are the same refusal.
     */
    private static function announcedType(string $blob): ?string
    {
        if (\strlen($blob) < 4) {
            return null;
        }

        /** @var array{1: int}|false $header */
        $header = unpack('N', substr($blob, 0, 4));

        if (false === $header) {
            return null;
        }

        $length = $header[1];

        if ($length <= 0 || \strlen($blob) < 4 + $length) {
            return null;
        }

        return substr($blob, 4, $length);
    }
}
