<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\ZoneTextParser;
use PHPUnit\Framework\TestCase;

/**
 * Reads a Zone/Légende support text, where a clickable zone is written inline as [[id|text]].
 * Markers rather than character offsets on purpose: the import format is produced by language
 * models and by hand, and neither can be trusted to count characters - see App\Service\ZoneJsonImporter.
 */
class ZoneTextParserTest extends TestCase
{
    public function testSegmentsSplitTextAndZonesInReadingOrder(): void
    {
        $segments = ZoneTextParser::segments('Le chat [[v|mange]] la souris.');

        self::assertSame([
            ['type' => 'text', 'value' => 'Le chat ', 'id' => ''],
            ['type' => 'zone', 'value' => 'mange', 'id' => 'v'],
            ['type' => 'text', 'value' => ' la souris.', 'id' => ''],
        ], $segments);
    }

    public function testAZoneMayContainMarkupCharacters(): void
    {
        // The whole point of the code support: zones wrap HTML tags, attribute quotes included.
        $segments = ZoneTextParser::segments('[[z1|<a href="/">]]Accueil[[z2|</a>]]');

        self::assertSame('z1', $segments[0]['id']);
        self::assertSame('<a href="/">', $segments[0]['value']);
        self::assertSame('Accueil', $segments[1]['value']);
        self::assertSame('</a>', $segments[2]['value']);
    }

    public function testCustomDelimitersLetLiteralBracketsSurvive(): void
    {
        // JS code legitimately contains "[[" - a question can switch its markers instead.
        $segments = ZoneTextParser::segments('const m = ⟦z1|[[1, 2]]⟧;', '⟦', '⟧');

        self::assertSame([
            ['type' => 'text', 'value' => 'const m = ', 'id' => ''],
            ['type' => 'zone', 'value' => '[[1, 2]]', 'id' => 'z1'],
            ['type' => 'text', 'value' => ';', 'id' => ''],
        ], $segments);
    }

    public function testZoneIdsComeBackInReadingOrder(): void
    {
        self::assertSame(['a', 'b'], ZoneTextParser::zoneIds('x [[a|1]] y [[b|2]]'));
        self::assertSame([], ZoneTextParser::zoneIds(null));
    }

    public function testZoneTextsAreKeyedById(): void
    {
        self::assertSame(
            ['a' => '<nav>', 'b' => '</nav>'],
            ZoneTextParser::zoneTexts("x [[a|<nav>]] y [[b|</nav>]]"),
        );
    }

    public function testLinesSplitOnNewlinesWithoutBreakingZones(): void
    {
        $lines = ZoneTextParser::lines("<body>\n  [[z1|<nav>]]\n</body>");

        self::assertCount(3, $lines);
        self::assertSame([['type' => 'text', 'value' => '<body>', 'id' => '']], $lines[0]);
        self::assertSame('  ', $lines[1][0]['value']);
        self::assertSame('z1', $lines[1][1]['id']);
        self::assertSame([['type' => 'text', 'value' => '</body>', 'id' => '']], $lines[2]);
    }

    public function testAnEmptyLineStaysALine(): void
    {
        // Blank lines are part of how code reads - collapsing them would renumber every line.
        self::assertCount(3, ZoneTextParser::lines("a\n\nb"));
    }

    public function testFindIssuesIsSilentOnAHealthySupport(): void
    {
        self::assertSame([], ZoneTextParser::findIssues('ok [[a|x]] et [[b|y]]'));
    }

    public function testFindIssuesReportsADuplicatedId(): void
    {
        self::assertSame(
            [['code' => 'duplicateId', 'id' => 'a']],
            ZoneTextParser::findIssues('[[a|x]] et [[a|y]]'),
        );
    }

    public function testFindIssuesReportsAStrayOpeningMarker(): void
    {
        // An opening delimiter that never closes is almost always a mangled zone - or code that
        // needs the per-question markers override. Either way the teacher must hear about it.
        self::assertSame(
            [['code' => 'strayMarker', 'id' => '']],
            ZoneTextParser::findIssues('un [[a|x sans fermeture'),
        );
    }
}
