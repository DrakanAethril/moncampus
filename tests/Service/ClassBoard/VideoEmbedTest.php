<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassBoard;

use App\Service\ClassBoard\VideoEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No list of domains (design/validated/tableau-virtuel.md, §7): the known players are embedded,
 * a file plays in <video>, anything else is tried in an iframe - and only http(s) ever comes out.
 */
class VideoEmbedTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'iframe', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'youtube short link with start' => ['https://youtu.be/dQw4w9WgXcQ?t=1m30s', 'iframe', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=90'];
        yield 'youtube shorts' => ['https://youtube.com/shorts/abcdefGHIJ1', 'iframe', 'https://www.youtube-nocookie.com/embed/abcdefGHIJ1'];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'iframe', 'https://player.vimeo.com/video/76979871'];
        yield 'dailymotion' => ['https://www.dailymotion.com/video/x8abcd1', 'iframe', 'https://www.dailymotion.com/embed/video/x8abcd1'];
        yield 'peertube, any instance' => ['https://tube.example.fr/w/9c9de5e8-0a1e-484a-b099-e80766180a6d', 'iframe', 'https://tube.example.fr/videos/embed/9c9de5e8-0a1e-484a-b099-e80766180a6d'];
        yield 'direct file' => ['https://media.example.org/cours/api.mp4', 'video', 'https://media.example.org/cours/api.mp4'];
        yield 'any other site' => ['https://www.lumni.fr/video/une-video', 'iframe', 'https://www.lumni.fr/video/une-video'];
    }

    #[DataProvider('addresses')]
    public function testAnAddressBecomesAPlayer(string $url, string $kind, string $src): void
    {
        $embed = (new VideoEmbed())->resolve($url);

        self::assertNotNull($embed);
        self::assertSame($kind, $embed['kind']);
        self::assertSame($src, $embed['src']);
        self::assertSame($url, $embed['url']);
    }

    public function testOnlyHttpAddressesAreAccepted(): void
    {
        $embed = new VideoEmbed();

        self::assertNull($embed->resolve('javascript:alert(1)'));
        self::assertNull($embed->resolve('data:text/html,<script>alert(1)</script>'));
        self::assertNull($embed->resolve('pas une adresse'));
        self::assertNull($embed->resolve(''));
    }
}
