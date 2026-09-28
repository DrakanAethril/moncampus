<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MobileSession;
use App\Entity\User;
use App\Enum\MobileApp;
use App\Security\MobileSessions;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Rester connecté » past the JWT's hour: a mobile sign-in hands a refresh token, which buys the
 * next JWT and is replaced at every exchange. A lost answer is forgiven for a minute; a replay past
 * that closes the whole session - both copies, since nobody can tell which one is the owner's.
 */
class MobileSessionApiTest extends FunctionalTestCase
{
    private User $user;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'mobile.teacher');
    }

    public function testARefreshTokenBuysAWorkingJwtAndIsReplaced(): void
    {
        $first = $this->open();

        $payload = $this->refresh($first);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $next = $payload->string('refreshToken');
        self::assertStringStartsWith('mcrt_', $next);
        self::assertNotSame($first, $next);

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$payload->string('token')]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // And the replacement goes on being exchanged, one generation after another.
        $this->refresh($next);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAnswerLostOnTheWayIsForgivenWithinTheMinute(): void
    {
        $first = $this->open();
        $lost = $this->refresh($first)->string('refreshToken');

        // The phone never received $lost, and presents $first again.
        $retried = $this->refresh($first);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // The pair that never arrived opens nothing; the one that did goes on.
        $this->refresh($lost);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAReplayPastTheWindowClosesTheWholeSession(): void
    {
        $first = $this->open();
        $current = $this->refresh($first)->string('refreshToken');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE mobile_session SET rotated_at = :at',
            ['at' => new \DateTimeImmutable('-5 minutes')->format('Y-m-d H:i:s')],
        );
        // The session is still in the identity map: without this the app would read the old row.
        $this->entityManager->clear();

        $replay = $this->refresh($first);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid_refresh_token', $replay->string('error'));

        // Whoever held the other copy is signed out too.
        $this->refresh($current);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(MobileSession::class)->findOneBy([])?->getRevokedAt());
    }

    public function testAnUnknownOrMalformedTokenBuysNothing(): void
    {
        $this->open();

        foreach (['', 'garbage', 'mcrt_'.str_repeat('a', 16).'_'.str_repeat('b', 64)] as $token) {
            $this->refresh($token);
            self::assertSame(401, $this->client->getResponse()->getStatusCode(), $token);
        }
    }

    public function testASessionIdleForThirtyDaysHasRunOut(): void
    {
        $token = $this->open();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE mobile_session SET expires_at = :at',
            ['at' => new \DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s')],
        );
        $this->entityManager->clear();

        $this->refresh($token);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testADeactivatedAccountRefreshesNothingAndIsToldSo(): void
    {
        $token = $this->open();
        $this->user->setInactiveDate(new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $payload = $this->refresh($token);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('account_disabled', $payload->string('error'));
        self::assertNotSame('', $payload->string('message'));
    }

    public function testSigningOutFromTheAppClosesTheSession(): void
    {
        $token = $this->open();

        $this->client->request('POST', '/api/token/revoke', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['refreshToken' => $token], \JSON_THROW_ON_ERROR));
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->refresh($token);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testMyProfileListsThePhoneAndSignsItOut(): void
    {
        $token = $this->open(MobileApp::Eco);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/profile');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Applications mobiles connectées', $crawler->text());
        self::assertStringContainsString('e-CO', $crawler->filter('.list-group-item')->text());

        $this->client->submit($crawler->selectButton('Déconnecter')->form());
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->refresh($token);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAnotherPersonsPhoneCannotBeSignedOut(): void
    {
        $this->open();
        $session = $this->entityManager->getRepository(MobileSession::class)->findOneBy(['user' => $this->user]);
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'mobile.other'));
        $this->client->request('GET', '/profile');

        $this->client->request('POST', \sprintf('/profile/mobile-sessions/%d/revoke', $session?->getId()), [
            '_token' => $this->csrfToken('profile_mobile_session_revoke'),
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function open(MobileApp $app = MobileApp::Campus): string
    {
        return static::getContainer()->get(MobileSessions::class)->open($this->user, $app, '127.0.0.1')->refreshToken;
    }

    private function refresh(string $refreshToken): JsonRequestPayload
    {
        $this->client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['refreshToken' => $refreshToken], \JSON_THROW_ON_ERROR));

        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }
}
