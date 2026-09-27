<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OAuthAuthorizationCode;
use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\OAuthToken;
use App\Enum\OAuthTokenKind;
use App\Repository\OAuthAuthorizationCodeRepository;
use App\Repository\OAuthTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Every secret the Claude connector hands out, and every exchange of one for another.
 *
 * **It flushes itself**, unlike the rest of this application's services. The token endpoint is a
 * one-purpose request, and two of its answers are an error *and* a write: a code or a refresh token
 * presented twice revokes the grant it belongs to (RFC 9700 § 4.14.2) - a revocation that must
 * reach the database even though the caller then answers 400 and has no reason to flush.
 */
final class TokenIssuer
{
    public const int CODE_TTL_SECONDS = 60;

    public const int ACCESS_TTL_SECONDS = 3600;

    public const int REFRESH_TTL_SECONDS = 60 * 86400;

    public const string CODE_PREFIX = 'mcac';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuthAuthorizationCodeRepository $codes,
        private readonly OAuthTokenRepository $tokens,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The code a consent sends back through the client's redirect URI.
     */
    public function issueCode(OAuthGrant $grant, string $redirectUri, string $codeChallenge, ?string $resource): string
    {
        $secret = OAuthSecret::mint(self::CODE_PREFIX);
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', self::CODE_TTL_SECONDS));

        $this->entityManager->persist(new OAuthAuthorizationCode($grant, $secret->selector, $secret->verifierHash, $redirectUri, $codeChallenge, $resource, $expiresAt));
        $this->entityManager->flush();

        return $secret->secret;
    }

    /**
     * @throws OAuthException
     */
    public function exchangeCode(OAuthClient $client, string $code, string $redirectUri, string $codeVerifier, ?string $resource): TokenPair
    {
        $now = $this->clock->now();
        $parts = OAuthSecret::split($code, self::CODE_PREFIX);
        $row = null === $parts ? null : $this->codes->findOneBySelector($parts['selector']);

        if (null === $parts || null === $row || !OAuthSecret::verifies($parts['verifier'], $row->getVerifierHash())) {
            throw OAuthException::invalidGrant('Unknown authorization code.');
        }

        $grant = $row->getGrant();

        if ($grant->getClient() !== $client) {
            throw OAuthException::invalidGrant('The authorization code was issued to another client.');
        }

        if ($row->isUsed()) {
            // A code is single-use. Seeing it twice means it leaked, so everything it opened closes.
            $grant->revoke($now);
            $this->entityManager->flush();

            throw OAuthException::invalidGrant('The authorization code has already been used.');
        }

        if ($row->isExpiredAt($now) || $grant->isRevoked()) {
            throw OAuthException::invalidGrant('The authorization code has expired.');
        }

        if ($row->getRedirectUri() !== $redirectUri) {
            throw OAuthException::invalidGrant('redirect_uri does not match the authorization request.');
        }

        if (!Pkce::verifies($codeVerifier, $row->getCodeChallenge())) {
            throw OAuthException::invalidGrant('code_verifier does not match the code challenge.');
        }

        if (null !== $resource && null !== $row->getResource() && $resource !== $row->getResource()) {
            throw new OAuthException('invalid_target', 'resource does not match the authorization request.');
        }

        $row->markUsed($now);

        return $this->issuePair($grant, $now);
    }

    /**
     * @throws OAuthException
     */
    public function refresh(OAuthClient $client, string $refreshToken): TokenPair
    {
        $now = $this->clock->now();
        $parts = OAuthSecret::split($refreshToken, OAuthTokenKind::Refresh->prefix());
        $row = null === $parts ? null : $this->tokens->findOneBySelector($parts['selector']);

        if (null === $parts || null === $row || OAuthTokenKind::Refresh !== $row->getKind()
            || !OAuthSecret::verifies($parts['verifier'], $row->getVerifierHash())) {
            throw OAuthException::invalidGrant('Unknown refresh token.');
        }

        $grant = $row->getGrant();

        if ($grant->getClient() !== $client) {
            throw OAuthException::invalidGrant('The refresh token was issued to another client.');
        }

        if ($row->isRotated()) {
            // Rotation means the legitimate client already holds the next one: whoever presents this
            // one again is either replaying a stolen copy or racing the owner. Neither is told apart,
            // so both lose the connection and the teacher reconnects once.
            $grant->revoke($now);
            $this->entityManager->flush();

            throw OAuthException::invalidGrant('The refresh token has already been used.');
        }

        if ($row->isExpiredAt($now) || $grant->isRevoked()) {
            throw OAuthException::invalidGrant('The refresh token has expired or was revoked.');
        }

        $row->markRotated($now);

        return $this->issuePair($grant, $now);
    }

    private function issuePair(OAuthGrant $grant, \DateTimeImmutable $now): TokenPair
    {
        $access = OAuthSecret::mint(OAuthTokenKind::Access->prefix());
        $refresh = OAuthSecret::mint(OAuthTokenKind::Refresh->prefix());

        $this->entityManager->persist(new OAuthToken($grant, OAuthTokenKind::Access, $access->selector, $access->verifierHash, $now, $now->modify(\sprintf('+%d seconds', self::ACCESS_TTL_SECONDS))));
        $this->entityManager->persist(new OAuthToken($grant, OAuthTokenKind::Refresh, $refresh->selector, $refresh->verifierHash, $now, $now->modify(\sprintf('+%d seconds', self::REFRESH_TTL_SECONDS))));
        $this->entityManager->flush();

        return new TokenPair($access->secret, $refresh->secret, self::ACCESS_TTL_SECONDS, $grant->getScope());
    }
}
