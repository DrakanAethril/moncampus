<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\NumericAnswerParser;
use App\Util\NumericVariableParser;
use PHPUnit\Framework\TestCase;

/**
 * What a student is allowed to type into a numeric answer, and the {name} placeholders a "calculée"
 * statement carries. Every rule in the parser exists because a French keyboard, a phone or a
 * copy-paste produces it - none of them should cost a right answer.
 */
class NumericAnswerParserTest extends TestCase
{
    public function testPlainNumbers(): void
    {
        self::assertSame(240.0, NumericAnswerParser::parse('240')['value']);
        self::assertSame(-3.5, NumericAnswerParser::parse('-3.5')['value']);
        self::assertSame(0.5, NumericAnswerParser::parse('.5')['value']);
        self::assertSame(1200.0, NumericAnswerParser::parse('1.2e3')['value']);
    }

    public function testFrenchDecimalComma(): void
    {
        self::assertSame(2.5, NumericAnswerParser::parse('2,5')['value']);
        self::assertSame(0.75, NumericAnswerParser::parse('0,75')['value']);
    }

    public function testThousandsSpacesIncludingTheNonBreakingOnes(): void
    {
        // A phone keyboard and a spreadsheet paste both produce U+00A0 here.
        self::assertSame(1234.5, NumericAnswerParser::parse('1 234,5')['value']);
        self::assertSame(1234.5, NumericAnswerParser::parse("1\u{00A0}234,5")['value']);
        self::assertSame(1234.5, NumericAnswerParser::parse("1\u{202F}234,5")['value']);
    }

    public function testTheLastSeparatorWins(): void
    {
        // "1.234,5" is French, "1,234.5" is English - a reader resolves both the same way.
        self::assertSame(1234.5, NumericAnswerParser::parse('1.234,5')['value']);
        self::assertSame(1234.5, NumericAnswerParser::parse('1,234.5')['value']);
    }

    public function testAUnitIsReadSeparatelyFromTheNumber(): void
    {
        self::assertSame(['value' => 240.0, 'unit' => 'km'], NumericAnswerParser::parse('240 km'));
        self::assertSame(['value' => 9.81, 'unit' => 'm/s²'], NumericAnswerParser::parse('9,81 m/s²'));
        self::assertSame(['value' => 240.0, 'unit' => null], NumericAnswerParser::parse('240'));
    }

    public function testNothingUsableReadsAsNoAnswer(): void
    {
        foreach (['', '   ', 'je ne sais pas', 'km', '-'] as $raw) {
            self::assertNull(NumericAnswerParser::parse($raw)['value'], sprintf('"%s" is not a number', $raw));
        }
    }

    public function testUnitComparisonForgivesCaseAndSpacing(): void
    {
        self::assertTrue(NumericAnswerParser::unitsMatch('km', 'KM'));
        self::assertTrue(NumericAnswerParser::unitsMatch('km', ' km '));
        self::assertTrue(NumericAnswerParser::unitsMatch(null, null));
        self::assertFalse(NumericAnswerParser::unitsMatch('km', 'm'));
        self::assertFalse(NumericAnswerParser::unitsMatch('km', null));
    }

    // --- statement placeholders ---

    public function testStatementVariablesAreFoundInOrderWithoutRepeats(): void
    {
        $text = 'Un train roule à {v} km/h pendant {t} h, puis encore {t} h.';

        self::assertSame(['v', 't'], NumericVariableParser::names($text));
    }

    public function testABareBraceIsNotAVariable(): void
    {
        // A code sample or a set in mathematics must not become a placeholder.
        self::assertSame([], NumericVariableParser::names('Soit E = { 1 ; 2 } et {} vide.'));
        self::assertSame([], NumericVariableParser::names('if (x) { return; }'));
    }

    public function testRenderingSubstitutesWhatItHasAndLeavesTheRest(): void
    {
        $text = 'Il roule à {v} km/h pendant {t} h.';

        self::assertSame('Il roule à 120 km/h pendant 2 h.', NumericVariableParser::render($text, ['v' => '120', 't' => '2']));
        // A placeholder with no value stays visible - a hole would read as a missing word.
        self::assertSame('Il roule à 120 km/h pendant {t} h.', NumericVariableParser::render($text, ['v' => '120']));
    }

    public function testSegmentsSplitTextAndPlaceholders(): void
    {
        $segments = NumericVariableParser::segments('à {v} km/h');

        self::assertSame(
            [
                ['type' => 'text', 'value' => 'à ', 'name' => ''],
                ['type' => 'variable', 'value' => '{v}', 'name' => 'v'],
                ['type' => 'text', 'value' => ' km/h', 'name' => ''],
            ],
            $segments,
        );
    }
}
