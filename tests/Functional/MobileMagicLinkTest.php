<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MagicLoginToken;
use App\Entity\MobileSession;
use App\Entity\User;
use App\Enum\MobileApp;
use App\Service\JsonRequestPayload;
use App\Service\MagicLoginService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Email;

/**
 * The mobile app's mailed link: the phone is reached by its `campusmanager://` scheme; the browser
 * build of the app (once at /campus-app/, retired) by the site's own link. A session a browser build
 * still opens stays listed under its own name.
 */
class MobileMagicLinkTest extends FunctionalTestCase
{
    public function testThePhoneIsSentItsDeepLink(): void
    {
        $user = $this->createConfirmedUser('magic.phone');

        static::getContainer()->get(MagicLoginService::class)->requestMobileLink($user, '127.0.0.1');

        $html = $this->sentHtml();
        self::assertStringContainsString('href="campusmanager://login/', $html);
        self::assertStringNotContainsString('/campus-app/', $html);
    }

    /**
     * The service rather than POST /api/magic-login/request: its rate limiter (per IP, over a cache
     * pool that survives the run) would make the answer depend on how often the suite ran today.
     *
     * The browser build of the app (/campus-app/) is retired: a browser still running it is sent the
     * site's own sign-in link, never the old /campus-app/?login= nor the phone's deep link.
     */
    public function testTheRetiredWebBuildIsSentTheSitesOwnLink(): void
    {
        $user = $this->createConfirmedUser('magic.pwa');

        static::getContainer()->get(MagicLoginService::class)
            ->requestLinkForApp($user, '127.0.0.1', MobileApp::CampusWeb);

        $html = $this->sentHtml();
        self::assertMatchesRegularExpression('#href="https?://[^/"]+/login/magic/[0-9a-f]+\.[0-9a-f]+"#', $html);
        self::assertStringNotContainsString('/campus-app/', $html);
        self::assertStringNotContainsString('campusmanager://', $html);
    }

    public function testThePhoneAppsKeepTheirDeepLink(): void
    {
        $user = $this->createConfirmedUser('magic.app');

        static::getContainer()->get(MagicLoginService::class)
            ->requestLinkForApp($user, '127.0.0.1', MobileApp::Campus);

        self::assertStringContainsString('href="campusmanager://login/', $this->sentHtml());
    }

    public function testALinkConsumedByThePwaOpensAWebSession(): void
    {
        $user = $this->createConfirmedUser('magic.pwa.consume');

        $this->client->request('POST', '/api/magic-login/consume', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'token' => $this->issueTokenFor($user),
            'client' => MobileApp::CampusWeb->value,
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('mcrt_', JsonRequestPayload::fromArray(
            (array) json_decode((string) $this->client->getResponse()->getContent(), true),
        )->string('refreshToken'));
        self::assertSame([MobileApp::CampusWeb], array_map(
            static fn (MobileSession $session): MobileApp => $session->getApp(),
            $this->entityManager()->getRepository(MobileSession::class)->findBy(['user' => $user]),
        ));
    }

    private function sentHtml(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        return (string) $message->getHtmlBody();
    }

    /** The raw token a mailed link carries - only its hash is stored, so it is written here. */
    private function issueTokenFor(User $user): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(16));

        $this->entityManager()->persist(new MagicLoginToken(
            $user,
            $selector,
            hash('sha256', $verifier),
            new \DateTimeImmutable('+15 minutes'),
            '127.0.0.1',
        ));
        $this->entityManager()->flush();

        return $selector.'.'.$verifier;
    }

    private function createConfirmedUser(string $username): User
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], $username);
        $user->setContactEmail($username.'@example.org');
        $user->setContactEmailVerifiedAt(new \DateTimeImmutable());
        $this->entityManager()->flush();

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
