<?php

declare(strict_types=1);

namespace App\Tests\Service\Equipment;

use App\Service\Equipment\EquipmentCode;
use App\Service\Equipment\EquipmentCodeCandidate;
use PHPUnit\Framework\TestCase;

/**
 * The label code of a unit-tracked piece of equipment: `CA-0142-0`.
 *
 * It is typed by hand twice - once on the Dymo, once in the search field - so the rule pinned here
 * is as much about reading a mistyped code back as about printing one. A check digit that the reader
 * silently corrected would defeat its own purpose: a wrong label must be *said* to be wrong.
 */
class EquipmentCodeTest extends TestCase
{
    public function testTheCodeIsThePrefixThePaddedNumberAndTheLuhnDigit(): void
    {
        self::assertSame('CA-0001-8', EquipmentCode::format(1));
        self::assertSame('CA-0002-6', EquipmentCode::format(2));
        self::assertSame('CA-0010-9', EquipmentCode::format(10));
        self::assertSame('CA-0142-0', EquipmentCode::format(142));
    }

    /** Four digits is a minimum, not a width: the ten-thousandth piece does not wrap round. */
    public function testTheNumberGrowsPastFourDigits(): void
    {
        self::assertSame('CA-10000-8', EquipmentCode::format(10000));
    }

    /**
     * Plain Luhn over the number. Luhn is blind to leading zeros, which is what lets `0142` and
     * `10000` share one rule however wide the padding grows.
     */
    public function testTheCheckDigitIsLuhn(): void
    {
        self::assertSame(0, EquipmentCode::checkDigit(142));
        self::assertSame(3, EquipmentCode::checkDigit(7992739871));
    }

    public function testAFreeTextSearchIsNotACode(): void
    {
        self::assertNull(EquipmentCode::candidates('souris'));
        self::assertNull(EquipmentCode::candidates(''));
        self::assertNull(EquipmentCode::candidates('CA'));
        self::assertNull(EquipmentCode::candidates('HDMI 2'));
    }

    public function testTheFullCodeReadsBackInEveryCase(): void
    {
        foreach (['CA-0142-0', 'ca-0142-0', ' CA 0142 0 ', 'CA0142-0', '142-0'] as $input) {
            self::assertEquals([new EquipmentCodeCandidate(142, 0)], EquipmentCode::candidates($input), $input);
        }
    }

    /** Without its check digit, a code is just its number - nothing to verify, nothing to refuse. */
    public function testTheNumberAloneIsEnough(): void
    {
        foreach (['142', '0142', 'CA0142', 'CA-0142', 'ca 142'] as $input) {
            $candidates = EquipmentCode::candidates($input);
            self::assertNotNull($candidates, $input);
            self::assertContainsEquals(new EquipmentCodeCandidate(142, null), $candidates, $input);
        }
    }

    public function testAnExplicitWrongCheckDigitIsKeptAndFlagged(): void
    {
        $candidates = EquipmentCode::candidates('CA-0142-7');

        self::assertEquals([new EquipmentCodeCandidate(142, 7)], $candidates);
        self::assertFalse($candidates[0]->isValid());
    }

    /**
     * Digits typed without any separator are ambiguous once the inventory passes a thousand pieces:
     * `01420` is number 1420, or number 142 with its check digit. Both are offered - but the second
     * only when its digit actually checks, otherwise every search would drag in a stranger.
     */
    public function testUnseparatedDigitsOfferBothReadingsOnlyWhenTheDigitChecks(): void
    {
        self::assertEquals(
            [new EquipmentCodeCandidate(1420, null), new EquipmentCodeCandidate(142, 0)],
            EquipmentCode::candidates('01420'),
        );
        self::assertEquals(
            [new EquipmentCodeCandidate(1427, null)],
            EquipmentCode::candidates('CA01427'),
        );
    }

    /** The sequence starts at 1: a zero is nobody. */
    public function testNumberZeroIsNeverACandidate(): void
    {
        self::assertSame([], EquipmentCode::candidates('0'));
        self::assertSame([], EquipmentCode::candidates('CA-0000-0'));
    }
}
