<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\Timecode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reading of a timecode written by a human - in the CSV column a teacher fills, and in the
 * editor's own field. Three spellings are accepted because all three are what people write:
 * "5:40", "1:05:40", and a bare number of seconds.
 */
final class TimecodeTest extends TestCase
{
    #[DataProvider('parsedTimecodes')]
    public function testParseReadsEveryAcceptedSpelling(string $raw, ?int $expected): void
    {
        self::assertSame($expected, Timecode::parse($raw));
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function parsedTimecodes(): iterable
    {
        yield 'minutes and seconds' => ['05:40', 340];
        yield 'no leading zero' => ['5:40', 340];
        yield 'hours' => ['1:05:40', 3940];
        yield 'padded hours' => ['00:02:15', 135];
        yield 'plain seconds' => ['125', 125];
        yield 'zero' => ['0:00', 0];
        yield 'surrounding spaces' => ['  2:15 ', 135];
        yield 'empty' => ['', null];
        yield 'words' => ['deux minutes', null];
        yield 'negative' => ['-5', null];
        // "5:75" is either 5 min 75 s or a typo, and guessing between the two silently moves a
        // question by a minute - a teacher would rather be told the line is wrong.
        yield 'seconds out of range' => ['5:75', null];
        yield 'minutes out of range in an hour form' => ['1:75:00', null];
        yield 'too many parts' => ['1:2:3:4', null];
    }

    #[DataProvider('formattedTimecodes')]
    public function testFormatWritesWhatTheScreensShow(int $seconds, string $expected): void
    {
        self::assertSame($expected, Timecode::format($seconds));
    }

    /** @return iterable<string, array{int, string}> */
    public static function formattedTimecodes(): iterable
    {
        yield 'under a minute' => [40, '0:40'];
        yield 'minutes' => [340, '5:40'];
        yield 'ten minutes' => [760, '12:40'];
        // The hour form only appears once there is an hour: a 12-minute lecture must not read
        // "0:12:40" on the timeline.
        yield 'an hour' => [3940, '1:05:40'];
        yield 'negative is floored' => [-10, '0:00'];
    }
}
