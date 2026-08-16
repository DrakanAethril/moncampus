<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ClamAvReply;
use PHPUnit\Framework\TestCase;

/**
 * What clamd answers to an INSTREAM scan, read as one of three outcomes.
 *
 * Kept apart from the socket so the interesting half is testable without one. The third outcome is
 * the one that matters: an ERROR reply is **not** "clean", and reading it as such is exactly how an
 * antivirus comes to be believed while letting everything through.
 */
class ClamAvReplyTest extends TestCase
{
    public function testACleanStreamIsClean(): void
    {
        $reply = ClamAvReply::parse("stream: OK\0");

        self::assertTrue($reply->isClean());
        self::assertNull($reply->signature());
        self::assertFalse($reply->isError());
    }

    public function testAnInfectedStreamNamesItsSignature(): void
    {
        $reply = ClamAvReply::parse("stream: Win.Test.EICAR_HDB-1 FOUND\0");

        self::assertFalse($reply->isClean());
        self::assertSame('Win.Test.EICAR_HDB-1', $reply->signature());
        self::assertFalse($reply->isError());
    }

    public function testAnErrorIsNeitherCleanNorInfected(): void
    {
        // clamd answers this when the stream is over StreamMaxLength, and refusing the upload is
        // the only honest reading: nothing was scanned.
        $reply = ClamAvReply::parse("INSTREAM size limit exceeded. ERROR\0");

        self::assertFalse($reply->isClean());
        self::assertNull($reply->signature());
        self::assertTrue($reply->isError());
    }

    public function testAnUnrecognisedReplyIsTreatedAsAnError(): void
    {
        // Fail closed on anything unexpected: an antivirus that silently lets files through when
        // it does not understand its own protocol is worse than none, because it is believed.
        foreach (['', "\0", 'something else entirely'] as $raw) {
            $reply = ClamAvReply::parse($raw);

            self::assertFalse($reply->isClean(), $raw);
            self::assertTrue($reply->isError(), $raw);
        }
    }

    public function testTrailingNullsAndWhitespaceAreIgnored(): void
    {
        // clamd terminates its reply with a NUL in "z" mode and with a newline in the older one.
        self::assertTrue(ClamAvReply::parse("stream: OK\n")->isClean());
        self::assertTrue(ClamAvReply::parse("stream: OK\0\n  ")->isClean());
    }
}
