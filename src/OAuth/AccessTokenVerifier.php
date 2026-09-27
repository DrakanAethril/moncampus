<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OAuthToken;
use App\Enum\OAuthTokenKind;
use App\Repository\OAuthTokenRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * « Does this bearer open the connector, and for whom? » - the whole of the question the MCP
 * firewall asks (App\Security\McpAccessTokenAuthenticator).
 *
 * Refuses, without saying which: an unknown or malformed secret, a refresh token presented as an
 * access token, an expired token, and a token whose grant was revoked from « Mon profil ».
 */
final readonly class AccessTokenVerifier
{
    public function __construct(
        private OAuthTokenRepository $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function verify(string $bearer): ?OAuthToken
    {
        $parts = OAuthSecret::split($bearer, OAuthTokenKind::Access->prefix());
        $token = null === $parts ? null : $this->tokens->findOneBySelector($parts['selector']);

        if (null === $parts || null === $token
            || OAuthTokenKind::Access !== $token->getKind()
            || !OAuthSecret::verifies($parts['verifier'], $token->getVerifierHash())
            || $token->isExpiredAt($this->clock->now())
            || $token->getGrant()->isRevoked()) {
            return null;
        }

        return $token;
    }
}
