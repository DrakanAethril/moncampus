<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\SecretBox;
use App\Service\Crypto\SecretBoxException;
use App\Service\Crypto\SecretBoxProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecretBoxTest extends TestCase
{
    private const string PLAIN = 'c1a4f0e2-9b3d-4a71-8f56-0d2e7a9b1c33';

    public function testASealedSecretComesBackUnchanged(): void
    {
        $box = $this->box();

        self::assertSame(self::PLAIN, $box->open($box->seal(self::PLAIN)));
    }

    public function testAnEmptySecretSurvivesTheRoundTrip(): void
    {
        // A blank provisioning secret is a real state (the host is read-only), and the caller
        // must not have to special-case it before sealing.
        $box = $this->box();

        self::assertSame('', $box->open($box->seal('')));
    }

    public function testTwoSealsOfTheSameSecretDiffer(): void
    {
        $box = $this->box();

        self::assertNotSame($box->seal(self::PLAIN), $box->seal(self::PLAIN));
    }

    public function testTheEnvelopeIsVersioned(): void
    {
        self::assertStringStartsWith('v1.', $this->box()->seal(self::PLAIN));
    }

    public function testASingleAlteredByteFailsAuthentication(): void
    {
        $box = $this->box();
        $sealed = $box->seal(self::PLAIN);

        [$version, $nonce, $cipher] = explode('.', $sealed);
        $rawCipher = (string) base64_decode($cipher, true);
        $rawCipher[3] = \chr(\ord($rawCipher[3]) ^ 0x01);
        $tampered = \sprintf('%s.%s.%s', $version, $nonce, base64_encode($rawCipher));

        $this->expectException(SecretBoxException::class);
        $box->open($tampered);
    }

    public function testAnotherKeyCannotOpenIt(): void
    {
        $sealed = $this->box()->seal(self::PLAIN);

        $this->expectException(SecretBoxException::class);
        (new SecretBox(base64_encode(str_repeat("\x02", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES))))->open($sealed);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEnvelopeProvider(): iterable
    {
        yield 'not an envelope at all' => ['plain-text'];
        yield 'unknown version' => ['v2.AAAA.BBBB'];
        yield 'too few parts' => ['v1.AAAA'];
        yield 'nonce of the wrong length' => ['v1.'.base64_encode('short').'.'.base64_encode('cipher')];
    }

    #[DataProvider('malformedEnvelopeProvider')]
    public function testAMalformedEnvelopeIsRejected(string $sealed): void
    {
        $this->expectException(SecretBoxException::class);
        $this->box()->open($sealed);
    }

    public function testAMissingKeyThrowsAtConstruction(): void
    {
        $this->expectException(SecretBoxException::class);
        new SecretBox('');
    }

    public function testAKeyOfTheWrongSizeThrowsAtConstruction(): void
    {
        $this->expectException(SecretBoxException::class);
        new SecretBox(base64_encode(str_repeat("\x01", 16)));
    }

    public function testAKeyThatIsNotBase64ThrowsAtConstruction(): void
    {
        $this->expectException(SecretBoxException::class);
        new SecretBox('not base64 !!');
    }

    public function testTheProviderReportsAnUnusableKeyInsteadOfThrowing(): void
    {
        $provider = new SecretBoxProvider('');

        self::assertFalse($provider->isAvailable());
        self::assertNotNull($provider->unavailableReason());
    }

    public function testTheProviderHandsOutTheBoxWhenTheKeyIsUsable(): void
    {
        $provider = new SecretBoxProvider(base64_encode(str_repeat("\x01", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        self::assertTrue($provider->isAvailable());
        self::assertNull($provider->unavailableReason());
        self::assertSame(self::PLAIN, $provider->get()->open($provider->get()->seal(self::PLAIN)));
    }

    private function box(): SecretBox
    {
        return new SecretBox(base64_encode(str_repeat("\x01", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }
}
