<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * The selector/verifier split every OAuth secret of the connector uses - codes, access tokens,
 * refresh tokens - on the model of App\Service\Jobboard\IngestTokenFactory.
 *
 * The secret handed out reads `<prefix>_<selector>_<verifier>`. The selector is stored as-is and
 * looked up (indexed, unique), so the lookup tells nothing; the verifier is stored hashed and only
 * ever compared with hash_equals(). Nothing readable in the database opens anything.
 */
final class OAuthSecret
{
    public const int SELECTOR_LENGTH = 16;

    public const int VERIFIER_LENGTH = 64;

    private function __construct(
        public readonly string $selector,
        public readonly string $verifierHash,
        public readonly string $secret,
    ) {
    }

    public static function mint(string $prefix): self
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_LENGTH / 2));
        $verifier = bin2hex(random_bytes(self::VERIFIER_LENGTH / 2));

        return new self($selector, self::hash($verifier), $prefix.'_'.$selector.'_'.$verifier);
    }

    /**
     * @return array{selector: string, verifier: string}|null null when the string is not a secret of
     *                                                         this prefix at all
     */
    public static function split(string $secret, string $prefix): ?array
    {
        $pattern = \sprintf('/^%s_([0-9a-f]{%d})_([0-9a-f]{%d})$/', preg_quote($prefix, '/'), self::SELECTOR_LENGTH, self::VERIFIER_LENGTH);

        if (1 !== preg_match($pattern, $secret, $matches)) {
            return null;
        }

        return ['selector' => $matches[1], 'verifier' => $matches[2]];
    }

    public static function verifies(string $verifier, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($verifier));
    }

    private static function hash(string $verifier): string
    {
        return hash('sha256', $verifier);
    }
}
