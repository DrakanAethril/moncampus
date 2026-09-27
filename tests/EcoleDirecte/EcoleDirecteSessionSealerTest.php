<?php

declare(strict_types=1);

namespace App\Tests\EcoleDirecte;

use App\EcoleDirecte\EcoleDirecteAccount;
use App\EcoleDirecte\EcoleDirecteHandshake;
use App\EcoleDirecte\EcoleDirecteSession;
use App\EcoleDirecte\EcoleDirecteSessionExpiredException;
use App\EcoleDirecte\EcoleDirecteSessionSealer;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The connection handed to the browser: it opens for the person it was sealed for, within its ten
 * minutes, as the kind it was sealed as - and for nothing else.
 */
class EcoleDirecteSessionSealerTest extends TestCase
{
    private MockClock $clock;

    private EcoleDirecteSessionSealer $sealer;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-27 10:00:00');
        $this->sealer = new EcoleDirecteSessionSealer('test-app-secret', $this->clock);
    }

    public function testASessionOpensAgainForItsOwner(): void
    {
        $owner = $this->user(7);
        $session = new EcoleDirecteSession(
            new EcoleDirecteHandshake(['GTK' => 'g'], 'g', 'tok', 'fa'),
            new EcoleDirecteAccount(1234, 'P', 'Jeanne', 'DUPONT', 'Institution', '2026-2027', [['id' => 44, 'code' => 'SIO1', 'label' => 'BTS SIO 1']]),
        );

        $opened = $this->sealer->openSession($this->sealer->sealSession($session, $owner), $owner);

        self::assertEquals($session, $opened);
    }

    public function testTheSealCarriesNoReadableSecret(): void
    {
        $owner = $this->user(7);
        $sealed = $this->sealer->sealSession(new EcoleDirecteSession(new EcoleDirecteHandshake([], null, 'visible-token'), new EcoleDirecteAccount(1, 'P', 'A', 'B')), $owner);

        self::assertStringNotContainsString('visible-token', $sealed);
        self::assertStringNotContainsString('visible-token', (string) base64_decode(strtr($sealed, '-_', '+/'), false));
    }

    public function testItExpiresAfterTenMinutes(): void
    {
        $owner = $this->user(7);
        $sealed = $this->sealer->sealSession(new EcoleDirecteSession(new EcoleDirecteHandshake(), new EcoleDirecteAccount(1, 'P', 'A', 'B')), $owner);

        $this->clock->sleep(EcoleDirecteSessionSealer::TTL_SECONDS + 1);

        $this->expectException(EcoleDirecteSessionExpiredException::class);
        $this->sealer->openSession($sealed, $owner);
    }

    public function testItOpensNothingForAnotherPerson(): void
    {
        $sealed = $this->sealer->sealSession(new EcoleDirecteSession(new EcoleDirecteHandshake(), new EcoleDirecteAccount(1, 'P', 'A', 'B')), $this->user(7));

        $this->expectException(EcoleDirecteSessionExpiredException::class);
        $this->sealer->openSession($sealed, $this->user(8));
    }

    public function testATamperedSealIsRefused(): void
    {
        $owner = $this->user(7);
        $sealed = $this->sealer->sealSession(new EcoleDirecteSession(new EcoleDirecteHandshake(), new EcoleDirecteAccount(1, 'P', 'A', 'B')), $owner);
        $sealed[20] = 'A' === $sealed[20] ? 'B' : 'A';

        $this->expectException(EcoleDirecteSessionExpiredException::class);
        $this->sealer->openSession($sealed, $owner);
    }

    public function testAPendingLoginIsNotASession(): void
    {
        $owner = $this->user(7);
        $pending = $this->sealer->sealPending(new EcoleDirecteHandshake([], null, 'tok'), ['MTk4MA=='], $owner);

        self::assertSame(['MTk4MA=='], $this->sealer->openPending($pending, $owner)['choices']);

        $this->expectException(EcoleDirecteSessionExpiredException::class);
        $this->sealer->openSession($pending, $owner);
    }

    public function testGarbageIsRefused(): void
    {
        $this->expectException(EcoleDirecteSessionExpiredException::class);
        $this->sealer->openSession('not a seal', $this->user(7));
    }

    private function user(int $id): User
    {
        $user = new User('teacher'.$id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
