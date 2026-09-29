<?php

declare(strict_types=1);

namespace App\Tests\Service\Sirene;

use App\Service\Sirene\Siret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A SIRET that can exist, by its own arithmetic: fourteen digits, Luhn, and La Poste's exception.
 */
class SiretTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function numbers(): iterable
    {
        yield 'AUDEFI, rue Bernard Lathière' => ['48931910300037', true];
        yield 'AUDEFI, siège' => ['48931910300029', true];
        yield 'typed with spaces' => ['489 319 103 00037', true];
        yield 'typed with dots' => ['489.319.103.00037', true];
        yield 'one digit wrong' => ['48931910300038', false];
        yield 'two digits swapped' => ['48931910300073', false];
        yield 'thirteen digits' => ['4893191030003', false];
        yield 'fifteen digits' => ['489319103000370', false];
        yield 'letters' => ['4893191030003A', false];
        yield 'La Poste, siège (plain Luhn)' => ['35600000000048', true];
        yield 'La Poste, an office (digits add up to a multiple of 5)' => ['35600000049837', true];
        yield 'La Poste, neither rule' => ['35600000049838', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('numbers')]
    public function testValidity(string $siret, bool $valid): void
    {
        self::assertSame($valid, Siret::isValid($siret));
    }

    public function testNormalizeStripsWhatANumberIsTypedWith(): void
    {
        self::assertSame('48931910300037', Siret::normalize(" 489 319\u{a0}103-00037 "));
    }

    public function testFormatGroupsTheSirenInThreesThenTheEstablishment(): void
    {
        self::assertSame('489 319 103 00037', Siret::format('48931910300037'));
        self::assertSame('abc', Siret::format('abc'), 'Something that is not a SIRET is shown as it is.');
    }

    public function testSiren(): void
    {
        self::assertSame('489319103', Siret::siren('489 319 103 00037'));
    }
}
