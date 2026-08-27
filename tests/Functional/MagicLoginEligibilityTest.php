<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MagicLoginToken;
use App\Entity\User;
use App\Service\MagicLoginService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who a magic link may be issued to, and consumed by (App\Service\MagicLoginService::isEligible()).
 *
 * The rule narrows on the **address**, never on the role: a link only ever reaches a contact address
 * its owner typed and then proved they read. ROLE_ADMIN was excluded on top of that until
 * 2026-08-27; the exclusion is gone, and this is what stops it creeping back in - it never made an
 * administrator's account harder to reach, it only left the people who cannot reset their own
 * password with nothing but `samba-tool` on the domain controller, which is not an answer somebody
 * locked out at 8am has.
 *
 * The refusal that *is* the rule is pinned next to it, on an administrator, because that is now
 * where the whole weight sits.
 */
class MagicLoginEligibilityTest extends FunctionalTestCase
{
    public function testAnAdministratorAsksForALinkAndOneIsIssued(): void
    {
        $admin = $this->createConfirmedUser(['ROLE_USER', 'ROLE_ADMIN'], 'magic.admin', 'magic.admin@example.org');

        $this->requestLinkFor($admin);

        self::assertSame(1, $this->tokensFor($admin), 'an administrator is no longer excluded from the mailed link');
    }

    public function testAnAddressNobodyHasConfirmedGetsNothing(): void
    {
        $user = $this->createConfirmedUser(['ROLE_USER', 'ROLE_ADMIN'], 'magic.unconfirmed', 'magic.unconfirmed@example.org');
        $user->setContactEmailVerifiedAt(null);
        $this->entityManager()->flush();

        $this->requestLinkFor($user);

        // Silently, and the browser is shown the same page either way: an address nobody has proved
        // they read is not somewhere a login may be sent, whatever the role of the person asking.
        self::assertSame(0, $this->tokensFor($user));
    }

    /** The other half: a token in hand is not enough, the question is asked again when it is used. */
    public function testAnAdministratorFollowsTheirOwnLinkAndIsLoggedIn(): void
    {
        $admin = $this->createConfirmedUser(['ROLE_USER', 'ROLE_ADMIN'], 'magic.follower', 'magic.follower@example.org');

        $this->followLink($this->issueTokenFor($admin));

        self::assertNotNull($this->client->getContainer()->get('security.token_storage')->getToken());
        self::assertStringNotContainsString('/login', (string) $this->client->getRequest()->getUri());
    }

    public function testALinkIssuedBeforeADeactivationStopsWorkingAfterIt(): void
    {
        $admin = $this->createConfirmedUser(['ROLE_USER', 'ROLE_ADMIN'], 'magic.revoked', 'magic.revoked@example.org');
        $token = $this->issueTokenFor($admin);

        $admin->setInactiveDate(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $this->followLink($token);

        self::assertNull($this->client->getContainer()->get('security.token_storage')->getToken());
        self::assertStringContainsString('/login', (string) $this->client->getRequest()->getUri());
    }

    /**
     * The service rather than the form, deliberately: what is under test is who may be issued a
     * link, and the public form's rate limiter (five an hour per IP, sliding window over a cache
     * pool that survives the run) would make the answer depend on how often the suite has been
     * run today.
     */
    private function requestLinkFor(User $user): void
    {
        static::getContainer()->get(MagicLoginService::class)->requestLink($user, '127.0.0.1');
    }

    private function tokensFor(User $user): int
    {
        return (int) $this->entityManager()
            ->createQuery('SELECT COUNT(t.id) FROM App\Entity\MagicLoginToken t WHERE t.user = :user')
            ->setParameter('user', $user)
            ->getSingleScalarResult();
    }

    /** The raw token a mailed link carries. Written here rather than read out of the mail, because
     *  only its hash is stored - the same fixture InactiveAccountLockoutTest builds. */
    private function issueTokenFor(User $user): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(16));

        $this->entityManager()->persist(new MagicLoginToken(
            $user,
            $selector,
            hash('sha256', $verifier),
            new \DateTimeImmutable('+1 hour'),
            '127.0.0.1',
        ));
        $this->entityManager()->flush();

        return $selector.'.'.$verifier;
    }

    private function followLink(string $token): void
    {
        $crawler = $this->client->request('GET', '/login/magic/'.$token);
        $this->client->submit($crawler->filter('form')->first()->form());
        $this->client->followRedirect();
    }

    /** @param list<string> $roles */
    private function createConfirmedUser(array $roles, string $username, string $email): User
    {
        $user = $this->createUser($roles, $username);
        $user->setContactEmail($email);
        $user->setContactEmailVerifiedAt(new \DateTimeImmutable());
        $this->entityManager()->flush();

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
