<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\SshPublicKey;
use PHPUnit\Framework\TestCase;

/**
 * What an administrator pastes into their profile, read as a value rather than trusted as a string.
 *
 * The keys below were generated with `ssh-keygen` and their fingerprints read back with
 * `ssh-keygen -lf`, so the expected values come from OpenSSH itself rather than from this class -
 * a fingerprint checked against our own implementation would only prove it is consistent with
 * itself, and an administrator compares what the screen shows with what their own machine says.
 *
 * The refusals matter more than the acceptances. A key that does not parse but is stored anyway
 * ends up in a machine's authorized_keys, where a broken line does not fail loudly: sshd skips it,
 * and the administrator simply cannot log in with no explanation anywhere.
 */
class SshPublicKeyTest extends TestCase
{
    private const string ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJ8OsB+eIoJlBgEnC57bQMdBVJ41SCgKen1bgc4E0hx marie@portable';
    private const string ED25519_FINGERPRINT = 'SHA256:nPlYJWQsKwhAEwerMspp86g/3Kxc1/SR6S+1QkyeN/4';
    private const string RSA = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQCvpM+kjosjDA/O+/XUCwvlxzD6jSVpQ4VfsVAWiKK5nmA6U4ep1iABHfboQc3t3Dki6iJGjqKDaitMJPb0bNWnOBUnpeRx3HcSGKeqlyuc/ilzGDY68/rfzTc33mWZiRdLcmNqkrMI7FETtDZBEmvFZzeGXcVJdxFgA5FQ+rvWKdt+9bovbL03awEsS3A83K/9ZNrvg0jmaHrXm1iRBOyqu8i/YMoC2aMHa0WK725IgOr0fwyv8Uf1SIstn5YG1mvUF4HQy9orN9Hr74oRIGyRE997sF+XOsJ8r30nOqOJhQPwqSjMlMW/cQsola9PnFNnNsEZsZmtyWg33pQbMVDz';

    public function testAnEd25519KeyIsReadWithItsTypeAndComment(): void
    {
        $key = SshPublicKey::parse(self::ED25519);

        self::assertSame('ssh-ed25519', $key->type);
        self::assertSame('marie@portable', $key->comment);
        self::assertSame(self::ED25519_FINGERPRINT, $key->fingerprint());
    }

    public function testAnRsaKeyWithNoCommentIsAccepted(): void
    {
        $key = SshPublicKey::parse(self::RSA);

        self::assertSame('ssh-rsa', $key->type);
        self::assertNull($key->comment);
    }

    /** A comment may hold spaces - ssh-keygen puts the machine name there and nobody quotes it. */
    public function testACommentKeepsItsSpaces(): void
    {
        self::assertSame('MacBook de Marie', SshPublicKey::parse(self::RSA.' MacBook de Marie')->comment);
    }

    /** Pasting from a terminal brings leading spaces and a trailing newline with it. */
    public function testSurroundingWhitespaceIsIgnored(): void
    {
        self::assertSame(self::ED25519_FINGERPRINT, SshPublicKey::parse("  \n\t".self::ED25519."  \n")->fingerprint());
    }

    /**
     * The sharp one: the announced type and the type written inside the blob must agree. Reading
     * only the first word would accept `ssh-rsa <an ed25519 body>`, which sshd rejects - and this
     * is not a hypothetical, it is what hand-editing a key line produces.
     */
    public function testAKeyWhoseAnnouncedTypeContradictsItsBodyIsRefused(): void
    {
        $body = explode(' ', self::ED25519)[1];

        self::assertNull(SshPublicKey::tryParse('ssh-rsa '.$body));
    }

    public function testGarbageIsRefused(): void
    {
        self::assertNull(SshPublicKey::tryParse('not a key at all'));
        self::assertNull(SshPublicKey::tryParse('ssh-ed25519'));
        self::assertNull(SshPublicKey::tryParse('ssh-ed25519 ***not-base64***'));
        self::assertNull(SshPublicKey::tryParse(''));
    }

    /**
     * The mistake worth naming: a private key pasted where the public one was asked for. It has to
     * be refused for the obvious reason, and it must never be echoed back into a message.
     */
    public function testAPrivateKeyIsRefused(): void
    {
        self::assertNull(SshPublicKey::tryParse("-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----"));
    }

    /**
     * Stored without its comment, deliberately: the comment is the only part an administrator can
     * write freely, it is shown back on the screen, and it travels into every machine's
     * authorized_keys. The label they give the key is what names it here instead.
     */
    public function testTheStoredFormIsTypeAndBodyOnly(): void
    {
        self::assertSame(implode(' ', \array_slice(explode(' ', self::ED25519), 0, 2)), SshPublicKey::parse(self::ED25519)->toStorage());
    }
}
