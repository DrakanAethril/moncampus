<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PostValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Same contract as QueryValueTest, on the POST body: nothing handed to this class may make it throw.
 *
 * The case that matters most is the empty string, because on a POST body it is not an edge case at
 * all - it is what every `<select>` carrying an "aucun" option posts when the teacher leaves it
 * alone, and what turned "poser une évaluation hors séquence" into a 400.
 */
class PostValueTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private static function request(array $body): Request
    {
        return Request::create('/', 'POST', $body);
    }

    public function testIntReadsANumber(): void
    {
        self::assertSame(12, PostValue::int(self::request(['sequence' => '12']), 'sequence'));
    }

    public function testIntFallsBackOnTheEmptyStringAnUnchosenSelectPosts(): void
    {
        self::assertSame(0, PostValue::int(self::request(['sequence' => '']), 'sequence'));
    }

    public function testIntFallsBackOnAMissingKeyAndOnRubbish(): void
    {
        self::assertSame(0, PostValue::int(self::request([]), 'sequence'));
        self::assertSame(0, PostValue::int(self::request(['sequence' => 'aucune']), 'sequence'));
        self::assertSame(0, PostValue::int(self::request(['sequence' => ['1', '2']]), 'sequence'));
    }

    public function testIntHonoursItsDefault(): void
    {
        self::assertSame(5, PostValue::int(self::request(['program' => '']), 'program', 5));
    }

    public function testNullableIntSeparatesNothingChosenFromZero(): void
    {
        self::assertNull(PostValue::nullableInt(self::request(['sequence' => '']), 'sequence'));
        self::assertNull(PostValue::nullableInt(self::request([]), 'sequence'));
        self::assertSame(0, PostValue::nullableInt(self::request(['sequence' => '0']), 'sequence'));
        self::assertSame(7, PostValue::nullableInt(self::request(['sequence' => '7']), 'sequence'));
    }

    public function testStringReadsAScalarAndDefaultsOnAnythingElse(): void
    {
        self::assertSame('DS', PostValue::string(self::request(['name' => 'DS']), 'name'));
        self::assertSame('', PostValue::string(self::request(['name' => ['a']]), 'name'));
        self::assertSame('', PostValue::string(self::request([]), 'name'));
    }

    public function testTrimmedStripsSurroundingSpace(): void
    {
        self::assertSame('DS', PostValue::trimmed(self::request(['name' => '  DS  ']), 'name'));
        self::assertSame('', PostValue::trimmed(self::request(['name' => '   ']), 'name'));
    }

    public function testBoolAcceptsTheUsualTruthyFormsAndNeverThrows(): void
    {
        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            self::assertTrue(PostValue::bool(self::request(['placed' => $truthy]), 'placed'), $truthy);
        }
        foreach (['', '0', 'false', 'off', 'no', 'peut-etre'] as $falsy) {
            self::assertFalse(PostValue::bool(self::request(['placed' => $falsy]), 'placed'), $falsy);
        }
        self::assertFalse(PostValue::bool(self::request([]), 'placed'));
        self::assertFalse(PostValue::bool(self::request(['placed' => ['1']]), 'placed'));
    }
}
