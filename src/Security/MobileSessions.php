<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\MobileSession;
use App\Entity\User;
use App\Enum\MobileApp;
use App\OAuth\OAuthSecret;
use App\Repository\MobileSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The mobile apps' sessions: what outlives the hour-long JWT, so « Rester connecté » means what it
 * says and a teacher following an e-CO race for two hours is not signed out halfway through.
 *
 * Every sign-in of the mobile API (password - ApiLdapAuthenticator -, or magic link) opens one, and
 * answers a JWT plus a refresh token. The refresh token **rotates** at every exchange, on the model
 * of the Claude connector (App\OAuth\TokenIssuer), with one allowance that connector does not need:
 * a phone in a wood loses answers. Presenting the generation just replaced again within
 * RETRY_WINDOW_SECONDS, while its successor has never been presented, is read as « the answer never
 * arrived » and answered with a fresh pair. Past that window it is a replay - a stolen copy, or the
 * owner racing it - and the whole session is revoked: whoever holds the other copy loses it too.
 *
 * **It flushes itself**, like TokenIssuer: a revocation is an error *and* a write, and the caller
 * answers 401 with no reason of its own to flush.
 */
final class MobileSessions
{
    public const string REFRESH_PREFIX = 'mcrt';

    public const int RETRY_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MobileSessionRepository $sessions,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly AccountStatusChecker $accountStatus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function open(User $user, MobileApp $app, ?string $ip): MobileTokenPair
    {
        $refresh = OAuthSecret::mint(self::REFRESH_PREFIX);

        $this->entityManager->persist(new MobileSession($user, $app, $refresh, $this->clock->now(), $ip));
        $this->entityManager->flush();

        return new MobileTokenPair($this->jwtManager->create($user), $refresh->secret);
    }

    /**
     * @throws MobileSessionRefused
     */
    public function refresh(string $refreshToken, ?string $ip): MobileTokenPair
    {
        $now = $this->clock->now();
        [$session, $parts] = $this->resolve($refreshToken);

        if (null === $session || $session->isRevoked() || $session->isExpiredAt($now)) {
            throw new MobileSessionRefused('invalid_refresh_token');
        }

        if ($session->isCurrent($parts['selector'])) {
            if (!$session->verifiesCurrent($parts['verifier'])) {
                throw new MobileSessionRefused('invalid_refresh_token');
            }
            $next = OAuthSecret::mint(self::REFRESH_PREFIX);
            $this->ensureActive($session);
            $session->rotate($next, $now, $ip);
        } else {
            if (!$session->verifiesPrevious($parts['verifier'])) {
                throw new MobileSessionRefused('invalid_refresh_token');
            }
            $rotatedAt = $session->getRotatedAt();
            if (null === $rotatedAt || $now->getTimestamp() - $rotatedAt->getTimestamp() > self::RETRY_WINDOW_SECONDS) {
                $session->revoke($now);
                $this->entityManager->flush();

                throw new MobileSessionRefused('invalid_refresh_token');
            }
            $next = OAuthSecret::mint(self::REFRESH_PREFIX);
            $this->ensureActive($session);
            $session->replaceCurrent($next, $now, $ip);
        }

        $this->entityManager->flush();

        return new MobileTokenPair($this->jwtManager->create($session->getUser()), $next->secret);
    }

    /**
     * « Se déconnecter » in the app. Answers whether a session was closed; either generation it
     * still knows closes it, and an unknown token changes nothing and says nothing more.
     */
    public function revokeByToken(string $refreshToken): bool
    {
        [$session, $parts] = $this->resolve($refreshToken);

        if (null === $session) {
            return false;
        }

        $verifies = $session->isCurrent($parts['selector'])
            ? $session->verifiesCurrent($parts['verifier'])
            : $session->verifiesPrevious($parts['verifier']);
        if (!$verifies) {
            return false;
        }

        $session->revoke($this->clock->now());
        $this->entityManager->flush();

        return true;
    }

    /** « Déconnecter » in « Mon profil »: only one of the reader's own sessions. */
    public function revokeForUser(User $user, int $sessionId): bool
    {
        $session = $this->sessions->find($sessionId);

        if (null === $session || $session->getUser() !== $user) {
            return false;
        }

        $session->revoke($this->clock->now());
        $this->entityManager->flush();

        return true;
    }

    /**
     * A deactivated account refreshes nothing - the same rule the sign-in and every JWT request
     * read (AccountStatusChecker). The session is left as it is: reactivating the account gives the
     * phone back its session, as it gives the web back its login.
     *
     * @throws MobileSessionRefused
     */
    private function ensureActive(MobileSession $session): void
    {
        if ($this->accountStatus->isRefused($session->getUser())) {
            throw new MobileSessionRefused('account_disabled');
        }
    }

    /**
     * @return array{0: ?MobileSession, 1: array{selector: string, verifier: string}}
     */
    private function resolve(string $refreshToken): array
    {
        $parts = OAuthSecret::split(trim($refreshToken), self::REFRESH_PREFIX);

        if (null === $parts) {
            return [null, ['selector' => '', 'verifier' => '']];
        }

        return [$this->sessions->findOneBySelector($parts['selector']), $parts];
    }
}
