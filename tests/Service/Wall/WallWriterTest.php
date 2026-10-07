<?php

declare(strict_types=1);

namespace App\Tests\Service\Wall;

use App\Service\Wall\WallRefusal;
use App\Service\Wall\WallWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two things a wall takes from a keyboard and draws back into a page: a link and a colour.
 * Both end up in an attribute of somebody else's screen, so what is refused matters as much as
 * what is accepted.
 */
class WallWriterTest extends TestCase
{
    #[DataProvider('links')]
    public function testALinkIsAPlainWebAddress(string $typed, ?string $expected): void
    {
        if (null === $expected) {
            $this->expectException(WallRefusal::class);
        }

        self::assertSame($expected, WallWriter::url($typed));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function links(): iterable
    {
        yield 'a full address' => ['https://www.ovhcloud.com/fr/vps', 'https://www.ovhcloud.com/fr/vps'];
        yield 'what people type: no scheme' => ['ovhcloud.com/fr/vps', 'https://ovhcloud.com/fr/vps'];
        yield 'plain http' => ['http://intranet.example/page', 'http://intranet.example/page'];
        yield 'a script' => ['javascript:alert(1)', null];
        yield 'a data address' => ['data:text/html,<p>x</p>', null];
        yield 'a file' => ['file:///etc/passwd', null];
        yield 'not an address' => ['https://', null];
    }

    #[DataProvider('colors')]
    public function testAColourIsSixHexDigits(string $sent, ?string $expected): void
    {
        if (null === $expected) {
            $this->expectException(WallRefusal::class);
        }

        self::assertSame($expected, WallWriter::color($sent));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function colors(): iterable
    {
        yield 'a swatch' => ['#d6e4f1', '#d6e4f1'];
        yield 'the picker, uppercased' => ['#D6E4F1', '#d6e4f1'];
        yield 'a named colour' => ['red', null];
        yield 'a style breaking out of its attribute' => ['#fff;background:url(x)', null];
        yield 'three digits' => ['#fff', null];
    }
}
