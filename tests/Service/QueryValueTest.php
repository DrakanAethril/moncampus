<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QueryValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The point of this class is that nothing it is handed can make it throw, so that is what most of
 * these cases assert: the empty string a blank filter submits, a word where a number was expected,
 * and the array a hand-written `?key[]=` produces all resolve to the default instead of a 400.
 */
class QueryValueTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private static function request(array $query): Request
    {
        return Request::create('/', 'GET', $query);
    }

    public function testIntReadsANumber(): void
    {
        self::assertSame(12, QueryValue::int(self::request(['classe' => '12']), 'classe'));
    }

    public function testIntFallsBackOnTheEmptyStringABlankFilterSubmits(): void
    {
        // The regression this class exists for: `<option value="">` + a GET form = `?classe=`.
        self::assertSame(0, QueryValue::int(self::request(['classe' => '']), 'classe'));
    }

    public function testIntFallsBackOnAMissingKeyAndOnRubbish(): void
    {
        self::assertSame(0, QueryValue::int(self::request([]), 'classe'));
        self::assertSame(0, QueryValue::int(self::request(['classe' => 'toutes']), 'classe'));
        self::assertSame(0, QueryValue::int(self::request(['classe' => ['1', '2']]), 'classe'));
    }

    public function testIntHonoursItsDefault(): void
    {
        self::assertSame(1, QueryValue::int(self::request(['page' => '']), 'page', 1));
    }

    public function testIntTruncatesADecimalRatherThanRejectingIt(): void
    {
        self::assertSame(3, QueryValue::int(self::request(['n' => '3.7']), 'n'));
    }

    public function testNullableIntSeparatesAbsentFromZero(): void
    {
        self::assertNull(QueryValue::nullableInt(self::request(['niveau' => '']), 'niveau'));
        self::assertNull(QueryValue::nullableInt(self::request([]), 'niveau'));
        self::assertSame(0, QueryValue::nullableInt(self::request(['niveau' => '0']), 'niveau'));
        self::assertSame(7, QueryValue::nullableInt(self::request(['niveau' => '7']), 'niveau'));
    }

    public function testStringReadsAScalarAndDefaultsOnAnythingElse(): void
    {
        self::assertSame('bonjour', QueryValue::string(self::request(['q' => 'bonjour']), 'q'));
        self::assertSame('', QueryValue::string(self::request(['q' => ['a']]), 'q'));
        self::assertSame('', QueryValue::string(self::request([]), 'q'));
    }

    public function testTrimmedStripsSurroundingSpace(): void
    {
        self::assertSame('bonjour', QueryValue::trimmed(self::request(['q' => '  bonjour  ']), 'q'));
        self::assertSame('', QueryValue::trimmed(self::request(['q' => '   ']), 'q'));
    }

    public function testIntListReadsARepeatedFilter(): void
    {
        self::assertSame([3, 7], QueryValue::intList(self::request(['cohorts' => ['3', '7']]), 'cohorts'));
    }

    public function testIntListSurvivesTheScalarShapeInputBagRefuses(): void
    {
        // `?cohorts=` is what the chip bar submits with every chip deselected, and InputBag::all()
        // throws on it rather than reading it as "no chip".
        self::assertSame([], QueryValue::intList(self::request(['cohorts' => '']), 'cohorts'));
        self::assertSame([], QueryValue::intList(self::request([]), 'cohorts'));
        self::assertSame([5], QueryValue::intList(self::request(['cohorts' => '5']), 'cohorts'));
    }

    public function testIntListDropsWhatCannotBeAnId(): void
    {
        self::assertSame([4], QueryValue::intList(self::request(['cohorts' => ['4', 'toutes', '', '0']]), 'cohorts'));
    }

    public function testBoolAcceptsTheUsualTruthyFormsAndNeverThrows(): void
    {
        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            self::assertTrue(QueryValue::bool(self::request(['past' => $truthy]), 'past'), $truthy);
        }
        foreach (['', '0', 'false', 'off', 'no', 'peut-etre'] as $falsy) {
            self::assertFalse(QueryValue::bool(self::request(['past' => $falsy]), 'past'), $falsy);
        }
        self::assertFalse(QueryValue::bool(self::request([]), 'past'));
        self::assertFalse(QueryValue::bool(self::request(['past' => ['1']]), 'past'));
    }
}
