<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

class PkceTest extends TestCase
{
    // The worked example of RFC 7636, appendix B.
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function testMatchesTheSpecificationsOwnExample(): void
    {
        self::assertSame(self::CHALLENGE, Pkce::challengeOf(self::VERIFIER));
        self::assertTrue(Pkce::verifies(self::VERIFIER, self::CHALLENGE));
        self::assertTrue(Pkce::isValidChallenge(self::CHALLENGE));
    }

    public function testRefusesAnotherVerifier(): void
    {
        self::assertFalse(Pkce::verifies(str_repeat('a', 43), self::CHALLENGE));
    }

    public function testRefusesAVerifierOutsideTheAllowedShape(): void
    {
        // Too short, and a character outside the unreserved set - both before any hashing.
        self::assertFalse(Pkce::verifies('short', Pkce::challengeOf('short')));
        self::assertFalse(Pkce::verifies(str_repeat('a', 42).'/', Pkce::challengeOf(str_repeat('a', 42).'/')));
    }

    public function testAPlainChallengeIsNotAChallenge(): void
    {
        // `plain` would put the verifier itself here; a 128-character string is not a S256 digest.
        self::assertFalse(Pkce::isValidChallenge(str_repeat('a', 128)));
    }
}
