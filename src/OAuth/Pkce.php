<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * Proof Key for Code Exchange (RFC 7636), S256 only - `plain` proves nothing a code interceptor
 * does not already hold, and OAuth 2.1 drops it. The client commits to sha256(verifier) before the
 * user ever sees the consent screen and reveals the verifier only at the token endpoint, so a code
 * caught on its way back is useless to whoever caught it.
 */
final class Pkce
{
    public const string METHOD = 'S256';

    public static function isValidChallenge(string $challenge): bool
    {
        // base64url of a 32-byte digest, unpadded: exactly 43 characters.
        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
    }

    public static function verifies(string $verifier, string $challenge): bool
    {
        if (1 !== preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            return false;
        }

        return hash_equals($challenge, self::challengeOf($verifier));
    }

    public static function challengeOf(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
