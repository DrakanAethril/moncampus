<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WikiSearchTerms;
use PHPUnit\Framework\TestCase;

/**
 * What a typed search becomes before it reaches MySQL's FULLTEXT engine.
 *
 * Boolean mode is what makes "adressage ip" mean "both words" rather than "either", and it is also
 * what makes a stray `+`, `-`, `*` or `"` a syntax error rather than a search - which surfaces as a
 * 500 on a screen where somebody typed a hyphen. Everything here exists so that cannot happen.
 */
class WikiSearchTermsTest extends TestCase
{
    public function testEachWordMustBePresent(): void
    {
        self::assertSame('+adressage* +ip*', WikiSearchTerms::forBooleanMode('adressage ip'));
    }

    public function testWordsAreTruncatedSoAPrefixMatches(): void
    {
        // Somebody typing "reseau" expects to find "réseaux" - the trailing * is what a wiki search
        // has to do, since nobody types the whole word.
        self::assertSame('+vlan*', WikiSearchTerms::forBooleanMode('vlan'));
    }

    public function testBooleanOperatorsTypedByAUserAreStrippedRatherThanObeyed(): void
    {
        // A hyphen in "plug-and-play" would mean NOT to the engine, and an unbalanced quote is a
        // syntax error - neither is what the person meant.
        self::assertSame('+plug* +and* +play*', WikiSearchTerms::forBooleanMode('plug-and-play'));
        self::assertSame('+test*', WikiSearchTerms::forBooleanMode('"test'));
        self::assertSame('+a* +b*', WikiSearchTerms::forBooleanMode('a +b'));
        self::assertSame('+reseau*', WikiSearchTerms::forBooleanMode('*reseau*'));
    }

    public function testAccentsAndCaseSurviveUntouched(): void
    {
        // The column is utf8mb4 with a case- and accent-insensitive collation; folding here would
        // only stop "réseau" from matching itself.
        self::assertSame('+Réseaux*', WikiSearchTerms::forBooleanMode('Réseaux'));
    }

    public function testASearchThatSaysNothingIsEmptyRatherThanMatchingEverything(): void
    {
        foreach (['', '   ', '-', '+*"', '()'] as $noise) {
            self::assertSame('', WikiSearchTerms::forBooleanMode($noise), var_export($noise, true));
        }
    }

    public function testTooManyWordsAreCappedRatherThanHandedToTheEngineWhole(): void
    {
        $typed = implode(' ', array_map(static fn (int $i): string => 'mot'.$i, range(1, 20)));

        self::assertCount(WikiSearchTerms::MAX_WORDS, explode(' ', WikiSearchTerms::forBooleanMode($typed)));
    }
}
