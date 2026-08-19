<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassImport;

use App\Service\ClassImport\NameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    private NameNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new NameNormalizer();
    }

    #[DataProvider('spellings')]
    public function testNormalize(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function spellings(): iterable
    {
        yield 'all caps' => ['DUPONT', 'Dupont'];
        yield 'all lowercase' => ['martin', 'Martin'];
        yield 'already capitalised' => ['Dupont', 'Dupont'];

        // The rule that makes the whole normalisation safe: a spelling that already carries case
        // information is never undone, because the directory takes these over read-only.
        yield 'mixed case survives' => ['MacLeod', 'MacLeod'];
        yield 'mixed case with apostrophe survives' => ["d'Arcy", "d'Arcy"];
        yield 'mixed case McDonald survives' => ['McDonald', 'McDonald'];

        yield 'hyphen recapitalises both parts' => ['jean-baptiste', 'Jean-Baptiste'];
        yield 'hyphen from all caps' => ['JEAN-BAPTISTE', 'Jean-Baptiste'];
        yield 'straight apostrophe' => ["D'ARCY", "D'Arcy"];
        yield 'typographic apostrophe' => ['D’ARCY', 'D’Arcy'];

        yield 'particle stays lowercase after the first word' => ['GOUBAULT DE BRUGIERE', 'Goubault de Brugiere'];
        yield 'particle at the head is capitalised' => ['DE BRUGIERE', 'De Brugiere'];
        yield 'several particles' => ['VAN DER BERG', 'Van der Berg'];
        yield 'della' => ['DELLA ROVERE', 'Della Rovere'];

        yield 'accents are kept and recapitalised' => ['ZOÉ', 'Zoé'];
        yield 'accented lowercase' => ['élodie', 'Élodie'];

        yield 'inner whitespace collapses' => ['  JEAN   PAUL  ', 'Jean Paul'];
        yield 'empty stays empty' => ['', ''];
        yield 'whitespace only' => ['   ', ''];
    }

    public function testReportsWhetherItChangedAnything(): void
    {
        self::assertTrue($this->normalizer->differs('DUPONT'));
        self::assertFalse($this->normalizer->differs('Dupont'));
        self::assertFalse($this->normalizer->differs('MacLeod'));
    }
}
