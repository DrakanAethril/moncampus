<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\Entity\OAuthAuthorizationCode;
use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\OAuthToken;
use App\Entity\User;
use App\OAuth\AccessTokenVerifier;
use App\OAuth\OAuthException;
use App\OAuth\Pkce;
use App\OAuth\TokenIssuer;
use App\Repository\OAuthAuthorizationCodeRepository;
use App\Repository\OAuthTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The whole life of a connection's secrets, over an in-memory store: consent → code → pair →
 * refresh → rotation, and the two replays that close the connection.
 */
class TokenIssuerTest extends TestCase
{
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const string RESOURCE = 'https://campus.example/mcp';

    /** @var list<object> */
    private array $persisted = [];

    private MockClock $clock;

    private TokenIssuer $issuer;

    private AccessTokenVerifier $verifier;

    private OAuthClient $client;

    private OAuthGrant $grant;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-27 10:00:00');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $codes = $this->createStub(OAuthAuthorizationCodeRepository::class);
        $codes->method('findOneBySelector')->willReturnCallback(fn (string $selector): ?OAuthAuthorizationCode => $this->find(OAuthAuthorizationCode::class, $selector));
        $tokens = $this->createStub(OAuthTokenRepository::class);
        $tokens->method('findOneBySelector')->willReturnCallback(fn (string $selector): ?OAuthToken => $this->find(OAuthToken::class, $selector));

        $this->issuer = new TokenIssuer($entityManager, $codes, $tokens, $this->clock);
        $this->verifier = new AccessTokenVerifier($tokens, $this->clock);
        $this->client = new OAuthClient('client-1', 'Claude', [self::REDIRECT], null);
        $this->grant = new OAuthGrant(new User('prof-001'), $this->client, 'library', $this->clock->now());
    }

    public function testACodeBuysAPairWhoseAccessTokenOpensTheConnector(): void
    {
        $pair = $this->issuer->exchangeCode($this->client, $this->code(), self::REDIRECT, self::VERIFIER, self::RESOURCE);

        self::assertSame(TokenIssuer::ACCESS_TTL_SECONDS, $pair->expiresIn);
        self::assertSame('library', $pair->scope);
        self::assertSame($this->grant, $this->verifier->verify($pair->accessToken)?->getGrant());
        // A refresh token is not an access token, whatever it is presented as.
        self::assertNull($this->verifier->verify($pair->refreshToken));
    }

    public function testNoSecretIsStoredInClear(): void
    {
        $code = $this->code();
        $pair = $this->issuer->exchangeCode($this->client, $code, self::REDIRECT, self::VERIFIER, null);

        $stored = serialize(array_map(static fn (object $row): array => (array) $row, $this->persisted));
        foreach ([$code, $pair->accessToken, $pair->refreshToken] as $secret) {
            self::assertStringNotContainsString(substr($secret, -64), $stored);
        }
    }

    public function testTheCodeMustComeBackWithItsOwnVerifierRedirectAndClient(): void
    {
        $this->assertRefused('invalid_grant', fn () => $this->issuer->exchangeCode($this->client, $this->code(), self::REDIRECT, str_repeat('b', 43), null));
        $this->assertRefused('invalid_grant', fn () => $this->issuer->exchangeCode($this->client, $this->code(), 'http://localhost/callback', self::VERIFIER, null));
        $this->assertRefused('invalid_grant', fn () => $this->issuer->exchangeCode(new OAuthClient('client-2', 'Other', [self::REDIRECT], null), $this->code(), self::REDIRECT, self::VERIFIER, null));
        $this->assertRefused('invalid_target', fn () => $this->issuer->exchangeCode($this->client, $this->code(), self::REDIRECT, self::VERIFIER, 'https://elsewhere.example/mcp'));
        $this->assertRefused('invalid_grant', fn () => $this->issuer->exchangeCode($this->client, 'mcac_not-a-code', self::REDIRECT, self::VERIFIER, null));

        self::assertFalse($this->grant->isRevoked());
    }

    public function testACodeLivesOneMinute(): void
    {
        $code = $this->code();
        $this->clock->sleep(TokenIssuer::CODE_TTL_SECONDS);

        $this->assertRefused('invalid_grant', fn () => $this->issuer->exchangeCode($this->client, $code, self::REDIRECT, self::VERIFIER, null));
    }

    public function testACodeUsedTwiceClosesTheConnection(): void
    {
        $code = $this->code();
        $pair = $this->issuer->exchangeCode($this->client, $code, self::REDIRECT, self::VERIFIER, null);

        $this->assertRefused('invalid_grant', fn () => $this->issuer->exchangeCode($this->client, $code, self::REDIRECT, self::VERIFIER, null));

        self::assertTrue($this->grant->isRevoked());
        self::assertNull($this->verifier->verify($pair->accessToken));
    }

    public function testRefreshingRotatesAndTheOldRefreshTokenCannotBeReplayed(): void
    {
        $first = $this->issuer->exchangeCode($this->client, $this->code(), self::REDIRECT, self::VERIFIER, null);
        $second = $this->issuer->refresh($this->client, $first->refreshToken);

        self::assertNotSame($first->refreshToken, $second->refreshToken);
        self::assertNotNull($this->verifier->verify($second->accessToken));

        $this->assertRefused('invalid_grant', fn () => $this->issuer->refresh($this->client, $first->refreshToken));

        // The replay closed everything, the legitimate client's newest pair included.
        self::assertTrue($this->grant->isRevoked());
        self::assertNull($this->verifier->verify($second->accessToken));
        $this->assertRefused('invalid_grant', fn () => $this->issuer->refresh($this->client, $second->refreshToken));
    }

    public function testAnAccessTokenExpiresAfterAnHour(): void
    {
        $pair = $this->issuer->exchangeCode($this->client, $this->code(), self::REDIRECT, self::VERIFIER, null);
        $this->clock->sleep(TokenIssuer::ACCESS_TTL_SECONDS);

        self::assertNull($this->verifier->verify($pair->accessToken));
        // ...while the refresh token still buys a new one.
        self::assertNotNull($this->verifier->verify($this->issuer->refresh($this->client, $pair->refreshToken)->accessToken));
    }

    public function testRevokingTheGrantClosesEverything(): void
    {
        $pair = $this->issuer->exchangeCode($this->client, $this->code(), self::REDIRECT, self::VERIFIER, null);
        $this->grant->revoke($this->clock->now());

        self::assertNull($this->verifier->verify($pair->accessToken));
        $this->assertRefused('invalid_grant', fn () => $this->issuer->refresh($this->client, $pair->refreshToken));
    }

    private function code(): string
    {
        return $this->issuer->issueCode($this->grant, self::REDIRECT, Pkce::challengeOf(self::VERIFIER), self::RESOURCE);
    }

    private function assertRefused(string $error, callable $attempt): void
    {
        try {
            $attempt();
            self::fail('The exchange was accepted.');
        } catch (OAuthException $exception) {
            self::assertSame($error, $exception->error);
        }
    }

    /**
     * @template T of OAuthAuthorizationCode|OAuthToken
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, string $selector): ?object
    {
        foreach ($this->persisted as $entity) {
            if ($entity instanceof $class && $entity->getSelector() === $selector) {
                return $entity;
            }
        }

        return null;
    }
}
