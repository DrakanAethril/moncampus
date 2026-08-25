<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MagicLoginToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * There are four ways into MonCampus, and a deactivated account must be refused by all four.
 *
 * The last one is the one that matters and the easiest to forget: deactivating somebody while they
 * are working has to put them out at their next action, not at their next login - which is the
 * request that will never come. Symfony's user checker does not cover it (ContextListener refreshes
 * a session's user without consulting one), so it has its own subscriber and its own test here.
 *
 * None of these tests reaches LDAP. That is itself part of what they prove for the two password
 * paths: App\Security\LdapCredentialsVerifier turns a deactivated account away before the bind, so
 * the directory is never asked about somebody the platform has already closed the door on.
 */
class InactiveAccountLockoutTest extends FunctionalTestCase
{
    private const string REFUSAL = 'Ce compte est désactivé';

    private function createDeactivatedUser(string $username = 'locked.user'): User
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], $username);
        $user->setInactiveDate(new \DateTimeImmutable());

        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    /** Path 1 - the web login form. */
    public function testTheLoginFormRefusesADeactivatedAccount(): void
    {
        $this->createDeactivatedUser();

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form[method="post"]')->first()->form([
            '_username' => 'locked.user',
            '_password' => 'whatever',
        ]));

        $this->client->followRedirect();

        self::assertStringContainsString(self::REFUSAL, (string) $this->client->getResponse()->getContent());
        self::assertNull($this->client->getContainer()->get('security.token_storage')->getToken());
    }

    /** Path 2a - POST /api/login, the mobile app's password login. */
    public function testTheMobileLoginRefusesADeactivatedAccount(): void
    {
        $this->createDeactivatedUser('locked.api');

        $this->client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => 'locked.api',
            'password' => 'whatever',
        ], \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();

        self::assertSame(401, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame('account_disabled', $payload['error'] ?? null);
        self::assertIsString($payload['message'] ?? null);
        self::assertStringContainsString(self::REFUSAL, $payload['message']);
    }

    /**
     * Path 2b - a JWT issued *before* the deactivation. The api firewall is stateless, so it
     * re-loads the user on every request and the checker runs again: the token stops opening
     * anything without waiting for its own expiry, and with nothing to revoke.
     */
    public function testAJwtIssuedBeforeTheDeactivationStopsWorking(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'locked.jwt');
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The token must open /api/me while the account is active.');

        $user->setInactiveDate(new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode(), 'The same token must stop opening it the moment the account is deactivated.');
    }

    /** Path 3 - a magic link mailed before the deactivation and followed after it. */
    public function testAMagicLinkRefusesADeactivatedAccount(): void
    {
        $user = $this->createDeactivatedUser('locked.magic');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(16));
        $entityManager->persist(new MagicLoginToken(
            $user,
            $selector,
            hash('sha256', $verifier),
            new \DateTimeImmutable('+1 hour'),
            '127.0.0.1',
        ));
        $entityManager->flush();

        $crawler = $this->client->request('GET', '/login/magic/'.$selector.'.'.$verifier);
        $this->client->submit($crawler->filter('form')->first()->form());
        $this->client->followRedirect();

        self::assertNull($this->client->getContainer()->get('security.token_storage')->getToken());
        self::assertStringContainsString('/login', (string) $this->client->getRequest()->getUri());
    }

    /**
     * Path 4 - the session already open. This is the one a user_checker does not cover.
     *
     * loginUser() writes the token straight into the session, exactly as a completed login would
     * have: from the app's point of view this is somebody who logged in this morning and is still
     * clicking around.
     */
    public function testAnOpenSessionFallsAtTheNextRequest(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'locked.session');
        $this->client->loginUser($user);

        $this->client->request('GET', '/');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The session must work while the account is active.');

        $user->setInactiveDate(new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/');
        $response = $this->client->getResponse();

        self::assertSame(302, $response->getStatusCode(), 'The very next request must be turned away.');
        self::assertStringContainsString('/login', (string) $response->headers->get('Location'));

        $this->client->followRedirect();
        self::assertStringContainsString(self::REFUSAL, (string) $this->client->getResponse()->getContent());
    }

    /**
     * The other half of lot 1: the administrator who deactivates their own account would be locked
     * out of the only screen that could undo it, and there is no second administrator to ask.
     */
    public function testAnAdministratorCannotDeactivateTheirOwnAccount(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'self.admin');
        $this->client->loginUser($admin);
        // csrfToken() borrows this request's session - the admin's own fiche answers 403 to an
        // administrator, so there is no rendered form carrying the token to submit instead.
        $this->client->request('GET', '/directory/users');

        $this->client->request('POST', '/directory/users/'.$admin->getId().'/deactivate', [
            '_token' => $this->csrfToken('directory_user_deactivate'),
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($admin->getInactiveDate());
    }
}
