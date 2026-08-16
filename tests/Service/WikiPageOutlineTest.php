<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WikiPageOutline;
use PHPUnit\Framework\TestCase;

/**
 * "Sur cette page", derived from the page's own headings and never stored.
 *
 * Same approach as App\Service\HelpArticleOutline - deriving instead of storing is what keeps the
 * two in step, since a writer who renames a heading has nothing else to update. Two differences,
 * both because a wiki page is longer than a help article: h3 is collected as well as h2 (a wiki
 * page really does have sub-sections), and the entries carry their level so the list can indent.
 */
class WikiPageOutlineTest extends TestCase
{
    public function testHeadingsBecomeEntriesAndGainTheAnchorTheyLinkTo(): void
    {
        $outline = (new WikiPageOutline())->build('<h2>Adressage IP</h2><p>…</p><h3>Masque</h3>');

        self::assertSame(
            [['id' => 'adressage-ip', 'title' => 'Adressage IP', 'level' => 2], ['id' => 'masque', 'title' => 'Masque', 'level' => 3]],
            $outline['entries'],
        );
        self::assertStringContainsString('<h2 id="adressage-ip">Adressage IP</h2>', $outline['html']);
        self::assertStringContainsString('<h3 id="masque">Masque</h3>', $outline['html']);
    }

    public function testAnAnchorTheWriterAlreadySetIsKept(): void
    {
        // The named-anchor plugin can put one there on purpose; renaming it under the writer would
        // break whatever already links to it.
        $outline = (new WikiPageOutline())->build('<h2 id="vlan">Réseaux locaux virtuels</h2>');

        self::assertSame([['id' => 'vlan', 'title' => 'Réseaux locaux virtuels', 'level' => 2]], $outline['entries']);
        self::assertStringContainsString('id="vlan"', $outline['html']);
    }

    public function testTwoHeadingsWithTheSameTitleGetDistinctAnchors(): void
    {
        $outline = (new WikiPageOutline())->build('<h2>Exercice</h2><h2>Exercice</h2>');

        self::assertSame(['exercice', 'exercice-2'], array_column($outline['entries'], 'id'));
    }

    public function testMarkupInsideAHeadingIsStrippedFromItsLabel(): void
    {
        $outline = (new WikiPageOutline())->build('<h2><strong>Plan</strong> du cours</h2>');

        self::assertSame('Plan du cours', $outline['entries'][0]['title']);
    }

    public function testAnEmptyHeadingIsLeftAloneRatherThanGivenAnEmptyAnchor(): void
    {
        $outline = (new WikiPageOutline())->build('<h2></h2><h2>Vrai titre</h2>');

        self::assertSame(['vrai-titre'], array_column($outline['entries'], 'id'));
    }

    public function testAPageWithNoHeadingHasNoOutlineAtAll(): void
    {
        // The panel is hidden rather than shown empty - most wiki pages are a paragraph.
        self::assertSame(['html' => '', 'entries' => []], (new WikiPageOutline())->build(null));
        self::assertSame([], (new WikiPageOutline())->build('<p>Juste un paragraphe.</p>')['entries']);
    }

    public function testTheTitleLevelIsIgnored(): void
    {
        // h1 is the page's own title, already drawn by the screen; repeating it in the outline
        // would make every page's sommaire open on itself.
        self::assertSame([], (new WikiPageOutline())->build('<h1>Titre</h1>')['entries']);
    }
}
