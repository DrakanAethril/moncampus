<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Enum\ExternalService;
use App\Enum\Feature;
use App\Enum\VisibilityLevel;
use App\OAuth\Pkce;
use App\Repository\OAuthGrantRepository;
use App\Security\ExternalServicePasswords;
use App\Security\PlatformPasswordCheck;
use App\Tests\Double\FakePlatformPasswordCheck;

/**
 * The Claude connector, end to end, the way claude.ai drives it: discovery → registration → consent
 * → code → token → /mcp. Each step is pinned on what the client actually reads - a status, a header,
 * a field - because an MCP client that gets one of them wrong does not say why, it just reports the
 * server as broken.
 */
class ClaudeConnectorOAuthFlowTest extends FunctionalTestCase
{
    private const string REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string SERVICE_PASSWORD = 'Service-Claude#2026';

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->set(PlatformPasswordCheck::class, new FakePlatformPasswordCheck());
        // The sign-in limiter keys on the identifier, which every test here shares, and its pool
        // outlives the run: emptied so each test starts with its ten attempts.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testAnAnonymousCallIsToldWhereToSignIn(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        $this->assertResponseStatusCodeSame(401);
        self::assertSame(
            'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"',
            $this->client->getResponse()->headers->get('WWW-Authenticate'),
        );
    }

    public function testTheDiscoveryDocumentsPointAtEachOther(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource/mcp');
        $this->assertResponseIsSuccessful();
        $resource = $this->json();
        self::assertSame('http://localhost/mcp', $resource['resource']);
        self::assertSame(['http://localhost'], $resource['authorization_servers']);

        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $this->assertResponseIsSuccessful();
        $server = $this->json();
        self::assertSame('http://localhost', $server['issuer']);
        self::assertSame('http://localhost/oauth/authorize', $server['authorization_endpoint']);
        self::assertSame('http://localhost/oauth/token', $server['token_endpoint']);
        self::assertSame('http://localhost/oauth/register', $server['registration_endpoint']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
        self::assertSame(['none'], $server['token_endpoint_auth_methods_supported']);
    }

    public function testRegistrationRefusesARedirectOutsideTheList(): void
    {
        $this->register(['https://evil.example/callback']);

        $this->assertResponseStatusCodeSame(400);
        self::assertSame('invalid_redirect_uri', $this->json()['error']);
    }

    public function testTheWholeFlowOpensTheConnectorAsTheTeacher(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        // Program::$visibility defaults to StaffAdmin, which hides a class from its own teachers'
        // pickers (findAllForTeacher); opened here so the class has something to say.
        $program = $this->createProgram([], [$teacher]);
        $program->setVisibility(VisibilityLevel::Everyone);
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $clientId = $this->registeredClient();

        $tokens = $this->authorizeAndExchange($teacher, $clientId);

        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(3600, $tokens['expires_in']);
        self::assertStringStartsWith('mcat_', $tokens['access_token']);
        self::assertStringStartsWith('mcrt_', $tokens['refresh_token']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $initialize = $this->mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        self::assertSame('2025-06-18', $this->at($initialize, 'result', 'protocolVersion'));
        self::assertSame('moncampus', $this->at($initialize, 'result', 'serverInfo', 'name'));

        $tools = $this->at($this->mcp($tokens['access_token'], 'tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        self::assertContains('whoami', array_column($tools, 'name'));

        $whoami = $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'whoami', 'arguments' => new \stdClass()]);
        self::assertFalse($this->at($whoami, 'result', 'isError'));
        self::assertSame('Prof.claude Test', $this->at($whoami, 'result', 'structuredContent', 'name'));
        $programs = $this->at($whoami, 'result', 'structuredContent', 'programsTaught');
        self::assertIsArray($programs);
        self::assertSame(['TEST-1'], array_column($programs, 'shortName'));
    }

    public function testANotificationIsAcceptedWithoutAnAnswer(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $tokens = $this->authorizeAndExchange($teacher, $this->registeredClient());

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: $this->bearer($tokens['access_token']), content: '{"jsonrpc":"2.0","method":"notifications/initialized"}');

        $this->assertResponseStatusCodeSame(202);
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testRevokingTheConnectionClosesTheConnectorAtOnce(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $tokens = $this->authorizeAndExchange($teacher, $this->registeredClient());

        $grant = $this->grantOf($teacher);
        $grant->revoke(new \DateTimeImmutable());
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: $this->bearer($tokens['access_token']), content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        $this->assertResponseStatusCodeSame(401);
        self::assertStringContainsString('error="invalid_token"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testTheProfileListsTheConnectionAndRevokesIt(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $tokens = $this->authorizeAndExchange($teacher, $this->registeredClient());

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/profile');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('value="http://localhost/mcp"', (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->selectButton('Révoquer')->form());
        $this->assertResponseRedirects('/profile');
        self::assertTrue($this->grantOf($teacher)->isRevoked());

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: $this->bearer($tokens['access_token']), content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testWithoutAServicePasswordThereIsNothingToAuthoriseWith(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $clientId = $this->registeredClient();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Autoriser'));
        self::assertCount(1, $crawler->filter('a[href^="/profile"]:contains("Définir le mot de passe du service")'));
    }

    public function testTheServicePasswordAloneSignsInNoLoginNeeded(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $this->setServicePassword($teacher);
        $clientId = $this->registeredClient();

        // Nobody signed in to MonCampus: the establishment login is never asked for.
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));
        $this->assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Autoriser')->form([
            'username' => 'prof.claude',
            'servicePassword' => self::SERVICE_PASSWORD,
        ]));

        self::assertStringStartsWith(self::REDIRECT.'?code=', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheConfirmedContactAddressIdentifiesToo(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $teacher->setContactEmail('prof.claude@example.test');
        $teacher->setContactEmailVerifiedAt(new \DateTimeImmutable());
        $this->setServicePassword($teacher);
        $clientId = $this->registeredClient();

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));
        $this->client->submit($crawler->selectButton('Autoriser')->form([
            'username' => 'prof.claude@example.test',
            'servicePassword' => self::SERVICE_PASSWORD,
        ]));

        self::assertStringStartsWith(self::REDIRECT.'?code=', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheEstablishmentPasswordOpensNothingHere(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $this->setServicePassword($teacher);
        $clientId = $this->registeredClient();

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));
        $this->client->submit($crawler->selectButton('Autoriser')->form([
            'username' => 'prof.claude',
            'servicePassword' => FakePlatformPasswordCheck::PLATFORM_PASSWORD,
        ]));

        $this->assertResponseStatusCodeSame(401);
        self::assertNull($this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('Identifiant ou mot de passe du service incorrect', (string) $this->client->getResponse()->getContent());
        self::assertSame([], static::getContainer()->get(OAuthGrantRepository::class)->findBy(['user' => $teacher]));
    }

    public function testGuessingTheServicePasswordIsCappedPerAccount(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $this->setServicePassword($teacher);
        $clientId = $this->registeredClient();
        $query = '/oauth/authorize?'.$this->authorizationQuery($clientId);

        for ($attempt = 1; $attempt <= 11; ++$attempt) {
            $crawler = $this->client->request('GET', $query);
            // A new address each time: the cap that matters is the account's.
            $this->client->setServerParameter('REMOTE_ADDR', '10.9.8.'.$attempt);
            $this->client->submit($crawler->selectButton('Autoriser')->form(['username' => 'prof.claude', 'servicePassword' => 'Mauvais-Essai#'.$attempt]));
        }
        $this->assertResponseStatusCodeSame(429);

        // Even the right password waits now.
        $crawler = $this->client->request('GET', $query);
        $this->client->submit($crawler->selectButton('Autoriser')->form(['username' => 'prof.claude', 'servicePassword' => self::SERVICE_PASSWORD]));
        $this->assertResponseStatusCodeSame(429);
    }

    public function testTheEstablishmentPasswordCannotBeChosenAsServicePassword(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/profile');

        $this->client->request('POST', '/profile/external-services/claude_connector/password', [
            '_token' => $this->csrfToken('external_service_password_claude_connector'),
            'password' => FakePlatformPasswordCheck::PLATFORM_PASSWORD,
            'confirmation' => FakePlatformPasswordCheck::PLATFORM_PASSWORD,
        ]);

        $this->assertResponseRedirects('/profile#external-service-claude_connector');
        self::assertNull(static::getContainer()->get(ExternalServicePasswords::class)->find($teacher, ExternalService::ClaudeConnector));
    }

    public function testChoosingThePasswordFromTheConsentScreenLeadsBackToIt(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $clientId = $this->registeredClient();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));
        $this->client->request('POST', '/profile/external-services/claude_connector/password', [
            '_token' => $this->csrfToken('external_service_password_claude_connector'),
            'password' => self::SERVICE_PASSWORD,
            'confirmation' => self::SERVICE_PASSWORD,
        ]);

        // Back to the same request - its query string normalised by Request::getUri().
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('http://localhost/oauth/authorize?', $location);
        self::assertStringContainsString('client_id='.$clientId, $location);
        self::assertStringContainsString('state=xyz', $location);
        self::assertNotNull(static::getContainer()->get(ExternalServicePasswords::class)->find($teacher, ExternalService::ClaudeConnector));
    }

    public function testChangingTheServicePasswordCutsTheConnector(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $tokens = $this->authorizeAndExchange($teacher, $this->registeredClient());

        $this->setServicePassword($teacher, 'Autre-Secret#2027');

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: $this->bearer($tokens['access_token']), content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRefusingSendsTheClientAnAccessDenied(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $clientId = $this->registeredClient();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));
        $this->client->submit($crawler->selectButton('Refuser')->form());

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT.'?error=access_denied&state=xyz', $location);
        self::assertSame([], static::getContainer()->get(OAuthGrantRepository::class)->findBy(['user' => $teacher]));
    }

    public function testAnUnknownRedirectIsShownRatherThanFollowed(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $clientId = $this->registeredClient();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/oauth/authorize?'.str_replace(urlencode(self::REDIRECT), urlencode('https://claude.ai/elsewhere'), $this->authorizationQuery($clientId)));

        $this->assertResponseStatusCodeSame(400);
        self::assertNull($this->client->getResponse()->headers->get('Location'));
    }

    public function testNothingIsAuthorisedWhenTheFeatureIsOff(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $this->setServicePassword($teacher);
        $clientId = $this->registeredClient();
        static::getContainer()->get('doctrine.orm.entity_manager')
            ->createQuery('UPDATE App\Entity\FeatureRoleSetting s SET s.enabled = false WHERE s.feature = :feature')
            ->setParameter('feature', Feature::ClaudeConnector)
            ->execute();

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));
        $this->client->submit($crawler->selectButton('Autoriser')->form(['username' => 'prof.claude', 'servicePassword' => self::SERVICE_PASSWORD]));

        $this->assertResponseStatusCodeSame(403);
        self::assertSame([], static::getContainer()->get(OAuthGrantRepository::class)->findBy(['user' => $teacher]));
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizeAndExchange(User $teacher, string $clientId): array
    {
        $this->setServicePassword($teacher);
        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->authorizationQuery($clientId));
        $this->assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Autoriser')->form(['servicePassword' => self::SERVICE_PASSWORD]));

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT.'?code=', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('xyz', $query['state'] ?? null);
        self::assertSame('http://localhost', $query['iss'] ?? null);

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'] ?? '',
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
            'client_id' => $clientId,
            'resource' => 'http://localhost/mcp',
        ]);
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    private function setServicePassword(User $user, string $password = self::SERVICE_PASSWORD): void
    {
        static::getContainer()->get(ExternalServicePasswords::class)->set($user, ExternalService::ClaudeConnector, $password);
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();
    }

    private function authorizationQuery(string $clientId): string
    {
        return http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => Pkce::challengeOf(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => 'library offline_access',
            'resource' => 'http://localhost/mcp',
        ]);
    }

    private function registeredClient(): string
    {
        $this->register([self::REDIRECT]);
        $this->assertResponseStatusCodeSame(201);

        $clientId = $this->json()['client_id'] ?? null;
        self::assertIsString($clientId);

        return $clientId;
    }

    /**
     * @param list<string> $redirectUris
     */
    private function register(array $redirectUris): void
    {
        // A fresh address per registration: the limiter keys on it, and its cache pool outlives the
        // run, so a fixed one would make the answer depend on how often the suite ran this hour.
        $address = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $address], content: (string) json_encode([
            'client_name' => 'Claude',
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => 'none',
        ]));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function mcp(string $accessToken, string $method, array $params = []): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: $this->bearer($accessToken), content: (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ]));
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, string> */
    private function bearer(string $accessToken): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken];
    }

    private function grantOf(User $user): OAuthGrant
    {
        $grant = static::getContainer()->get(OAuthGrantRepository::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(OAuthGrant::class, $grant);

        return $grant;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
