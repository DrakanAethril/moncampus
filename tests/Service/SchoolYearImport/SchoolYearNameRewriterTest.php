<?php

declare(strict_types=1);

namespace App\Tests\Service\SchoolYearImport;

use App\Service\SchoolYearImport\SchoolYearNameRewriter;
use PHPUnit\Framework\TestCase;

/**
 * R8: a name is taken over as it is, except the source year written in it, which becomes the target
 * year in the same form.
 */
class SchoolYearNameRewriterTest extends TestCase
{
    private static function rewriter(): SchoolYearNameRewriter
    {
        return SchoolYearNameRewriter::between(2026, 2027, 2027, 2028);
    }

    public function testDashForm(): void
    {
        self::assertSame('BTS SIO 1 2027-2028', self::rewriter()->rewrite('BTS SIO 1 2026-2027'));
    }

    public function testSlashForm(): void
    {
        self::assertSame('Semestres 2027/2028', self::rewriter()->rewrite('Semestres 2026/2027'));
    }

    public function testShortForm(): void
    {
        self::assertSame('SIO1 27-28 ? non : SIO1 2027-28', self::rewriter()->rewrite('SIO1 27-28 ? non : SIO1 2026-27'));
    }

    public function testSpacesAroundTheSeparatorAreKept(): void
    {
        self::assertSame('Alternance 2027 - 2028', self::rewriter()->rewrite('Alternance 2026 - 2027'));
    }

    public function testANameWithoutTheYearIsUnchanged(): void
    {
        self::assertSame('BTS SIO 1re année', self::rewriter()->rewrite('BTS SIO 1re année'));
        // Another year, or a lone year, is not the source year.
        self::assertSame('Promo 2025-2026', self::rewriter()->rewrite('Promo 2025-2026'));
        self::assertSame('Session 2026', self::rewriter()->rewrite('Session 2026'));
    }

    public function testTheYearWrittenTwiceIsRewrittenTwice(): void
    {
        self::assertSame('2027-2028 (2027/2028)', self::rewriter()->rewrite('2026-2027 (2026/2027)'));
    }

    public function testDigitsAroundTheYearAreNotAYear(): void
    {
        self::assertSame('Code 12026-20271', self::rewriter()->rewrite('Code 12026-20271'));
    }

    public function testBackwardFromAMoreRecentYear(): void
    {
        self::assertSame('BTS 2025-2026', SchoolYearNameRewriter::between(2026, 2027, 2025, 2026)->rewrite('BTS 2026-2027'));
    }

    public function testShortFormAcrossACentury(): void
    {
        self::assertSame('BTS 2100-01', SchoolYearNameRewriter::between(2098, 2099, 2100, 2101)->rewrite('BTS 2098-99'));
    }

    public function testRewriteOrSuffixAddsTheTargetYearWhenNoneWasWritten(): void
    {
        self::assertSame('Semestres BTS 2027-2028', self::rewriter()->rewriteOrSuffix('Semestres BTS'));
        self::assertSame('Semestres 2027-2028', self::rewriter()->rewriteOrSuffix('Semestres 2026-2027'));
    }

    public function testRewriteOrSuffixDoesNotAddTheYearTwice(): void
    {
        self::assertSame('Semestres 2027-2028', self::rewriter()->rewriteOrSuffix('Semestres 2027-2028'));
    }

    public function testTargetLabel(): void
    {
        self::assertSame('2027-2028', self::rewriter()->targetLabel());
        self::assertSame('2026-2027', self::rewriter()->sourceLabel());
    }
}
