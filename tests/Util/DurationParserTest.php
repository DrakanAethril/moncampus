<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\DurationParser;
use PHPUnit\Framework\TestCase;

/**
 * Reading a hand-written duration as a number of minutes.
 *
 * Lifted out of App\Command\ImportNotionSequencesCommand, which had it as a private method, so that
 * the sequence import assistant reads "4 h" and "20 min" exactly the way the Notion import already
 * did. These cases pin the behaviour that was there: they are the contract, not a wish list.
 *
 * The decisive one is testABareNumberIsRefused. SeanceTemplate::$duree and
 * SeancePhaseTemplate::$duree are DECIMAL(10,2) columns holding MINUTES, so "1.5" written for
 * "1 h 30" would be stored happily and displayed as "2 min" - a wrong value that nothing protests
 * about. Refusing it here is what lets the import report it instead.
 */
class DurationParserTest extends TestCase
{
    public function testReadsHoursAndMinutes(): void
    {
        self::assertSame('240', DurationParser::minutes('4 h'));
        self::assertSame('240', DurationParser::minutes('4h'));
        self::assertSame('240', DurationParser::minutes('4H'));
        self::assertSame('75', DurationParser::minutes('1 h 15'));
        self::assertSame('80', DurationParser::minutes('1h20'));
        self::assertSame('90', DurationParser::minutes('1 h 30'));
    }

    public function testReadsMinutes(): void
    {
        self::assertSame('20', DurationParser::minutes('20 min'));
        self::assertSame('55', DurationParser::minutes('55 minutes'));
        self::assertSame('55', DurationParser::minutes('55’'));
        self::assertSame('55', DurationParser::minutes("55'"));
    }

    /**
     * A range keeps its low bound. The design's step-4 mockup shows "« 30–40 min » → 40 min", which
     * is the *model's* arbitration declared in the import report - the format asks for a single
     * value, so the server only ever sees a range when the model ignored that. When it does, taking
     * the low bound is the pre-existing behaviour and the import warns about the value it kept.
     */
    public function testARangeKeepsItsLowBound(): void
    {
        self::assertSame('30', DurationParser::minutes('30-40 minutes'));
        self::assertSame('20', DurationParser::minutes('20 - 25 min'));
    }

    /** Two groups running the same séance is one séance's duration; "2h + 2h" is a total. */
    public function testAdditionsAreSummedButAnythingElseKeepsTheFirstValue(): void
    {
        self::assertSame('240', DurationParser::minutes('2h + 2h'));
        self::assertSame('80', DurationParser::minutes('1h20 1/2G'));
    }

    public function testABareNumberIsRefused(): void
    {
        self::assertNull(DurationParser::minutes('1.5'));
        self::assertNull(DurationParser::minutes('240'));
        self::assertNull(DurationParser::minutes('4'));
    }

    public function testAnEmptyOrUnreadableValueIsNull(): void
    {
        self::assertNull(DurationParser::minutes(null));
        self::assertNull(DurationParser::minutes(''));
        self::assertNull(DurationParser::minutes('   '));
        self::assertNull(DurationParser::minutes('une matinée'));
        self::assertNull(DurationParser::minutes('0 min'));
    }
}
