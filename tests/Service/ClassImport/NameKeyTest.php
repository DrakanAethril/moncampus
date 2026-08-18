<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassImport;

use App\Service\ClassImport\NameKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameKeyTest extends TestCase
{
    public function testFoldsCaseAndAccents(): void
    {
        self::assertTrue(NameKey::of('Zoé', 'MÜLLER')->equals(NameKey::of('zoe', 'muller')));
    }

    public function testTreatsHyphenApostropheAndSpaceAlike(): void
    {
        self::assertTrue(NameKey::of('Jean-Baptiste', 'Dupont')->equals(NameKey::of('Jean Baptiste', 'Dupont')));
        self::assertTrue(NameKey::of('Sofia', 'Bachir-bey')->equals(NameKey::of('Sofia', 'Bachir Bey')));
        self::assertTrue(NameKey::of('Marie', "d'Arcy")->equals(NameKey::of('Marie', 'd Arcy')));
        self::assertTrue(NameKey::of('Marie', 'd’Arcy')->equals(NameKey::of('Marie', "d'Arcy")));
    }

    public function testCollapsesRepeatedAndSurroundingWhitespace(): void
    {
        self::assertTrue(NameKey::of('  Jean   Paul ', ' Dupont  ')->equals(NameKey::of('Jean Paul', 'Dupont')));
    }

    public function testFirstnameAndLastnameAreNotInterchangeable(): void
    {
        self::assertFalse(NameKey::of('Martin', 'Dupont')->equals(NameKey::of('Dupont', 'Martin')));
    }

    public function testDistinctPeopleKeepDistinctKeys(): void
    {
        self::assertFalse(NameKey::of('Martin', 'Dupont')->equals(NameKey::of('Martine', 'Dupont')));
    }

    // The key is used as an array index throughout the analysis, so its string form has to be
    // stable and to keep the two parts apart - "Jean Paul|Dupont" must never collide with
    // "Jean|Paul Dupont".
    public function testSeparatesTheTwoParts(): void
    {
        self::assertSame('jean paul|dupont', NameKey::of('Jean-Paul', 'DUPONT')->value);
        self::assertNotSame(NameKey::of('Jean', 'Paul Dupont')->value, NameKey::of('Jean Paul', 'Dupont')->value);
    }

    #[DataProvider('accentedSpellings')]
    public function testStripsEveryAccentedLetterTheSchoolActuallyWrites(string $accented, string $plain): void
    {
        self::assertTrue(NameKey::of($accented, 'Dupont')->equals(NameKey::of($plain, 'Dupont')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function accentedSpellings(): iterable
    {
        yield 'a' => ['Àáâäã', 'Aaaaa'];
        yield 'c' => ['Çç', 'Cc'];
        yield 'e' => ['Èéêë', 'Eeee'];
        yield 'i' => ['Ìíîï', 'Iiii'];
        yield 'o' => ['Òóôöõ', 'Ooooo'];
        yield 'u' => ['Ùúûü', 'Uuuu'];
        yield 'n' => ['Ññ', 'Nn'];
    }
}
