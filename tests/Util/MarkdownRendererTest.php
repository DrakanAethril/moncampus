<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Markdown read into the two shapes this application's fields actually have.
 *
 * The distinction is the whole point, and it is a fact about the schema rather than a preference:
 * of the eleven text fields of a séquence/séance/phase, exactly ONE is HTML -
 * SeanceTemplate::$cahierDeTexteDescription, rendered `|raw` in library/seance_show.html.twig. Every
 * other one is escaped under `white-space: pre-wrap` and edited in a bare textarea, so writing
 * "<p>…</p>" into it would show the teacher its tags. App\Command\ImportNotionSequencesCommand
 * already made that split; this class is that split, extracted and testable.
 *
 * toPlainText() and toHtml() are lifted from it unchanged - these cases pin what was there.
 * toRichHtml() is new, for the import assistant: real séquence sheets are full of tables (the
 * Ansible kit's O1→O13 grid, its evaluation table), and toHtml() drops them entirely.
 */
class MarkdownRendererTest extends TestCase
{
    public function testPlainTextFlattensLinksAndDropsBoldMarkers(): void
    {
        self::assertSame('Documentation (https://docs.ansible.com)', MarkdownRenderer::toPlainText('[Documentation](https://docs.ansible.com)'));
        self::assertSame('https://docs.ansible.com', MarkdownRenderer::toPlainText('[https://docs.ansible.com](https://docs.ansible.com)'));
        self::assertSame('Public : BTS SIO', MarkdownRenderer::toPlainText('**Public :** BTS SIO'));
    }

    /**
     * A pipe table survives as its own lines. Under `pre-wrap` it still reads as a table, and the
     * textarea that edits the field round-trips it - which is what keeps the kit's O1→O13 grid from
     * being flattened into prose in a field that cannot hold HTML.
     *
     * Its **separator row** is the exception, and goes. `|---|---|` is punctuation addressed to a
     * Markdown parser: in a plain-text field there is no parser, so it reaches the teacher as a line
     * of dashes in the middle of their own table (seen on the séquence screen, 2026-08-13). The rows
     * that carry something stay, and only the one that carries nothing is dropped.
     */
    public function testPlainTextKeepsTableRowsButDropsTheSeparator(): void
    {
        $markdown = "| Code | Objectif |\n|---|---|\n| O1 | Expliquer le principe agentless |";

        self::assertSame("| Code | Objectif |\n| O1 | Expliquer le principe agentless |", MarkdownRenderer::toPlainText($markdown));
    }

    public function testTheSeparatorGoesInEverySpellingItIsWrittenIn(): void
    {
        foreach (['|---|---|', '| --- | --- |', '|:---|---:|', '| :---: |', '---|---'] as $separator) {
            self::assertSame('a', MarkdownRenderer::toPlainText("a\n".$separator), $separator.' should be dropped');
        }
    }

    /** A horizontal rule is not a table separator, and neither is a row that says something. */
    public function testWhatMerelyLooksLikeASeparatorIsKept(): void
    {
        self::assertSame('---', MarkdownRenderer::toPlainText('---'));
        self::assertSame('| - | Reste à faire |', MarkdownRenderer::toPlainText('| - | Reste à faire |'));
        self::assertSame('a | b', MarkdownRenderer::toPlainText('a | b'));
    }

    /** And it leaves no hole behind in the HTML paths either - not an empty paragraph, not a <br>. */
    public function testTheSeparatorLeavesNoEmptyLineInHtml(): void
    {
        self::assertSame('<p>| Code |<br>| O1 |</p>', MarkdownRenderer::toHtml("| Code |\n|---|\n| O1 |"));
    }

    public function testHtmlWrapsParagraphsAndLists(): void
    {
        self::assertSame('<p>Une ligne</p>', MarkdownRenderer::toHtml('Une ligne'));
        self::assertSame('<p>Deux<br>lignes</p>', MarkdownRenderer::toHtml("Deux\nlignes"));
        self::assertSame('<p>Un</p><p>Deux</p>', MarkdownRenderer::toHtml("Un\n\nDeux"));
        self::assertSame('<ul><li>Un</li><li>Deux</li></ul>', MarkdownRenderer::toHtml("- Un\n- Deux"));
        self::assertSame('<p>Intro</p><ul><li>Un</li></ul>', MarkdownRenderer::toHtml("Intro\n- Un"));
    }

    public function testHtmlEscapesWhatItDidNotProduce(): void
    {
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', MarkdownRenderer::toHtml('<script>alert(1)</script>'));
    }

    public function testHtmlIsNullWhenThereIsNothingToRender(): void
    {
        self::assertNull(MarkdownRenderer::toHtml(''));
        self::assertNull(MarkdownRenderer::toHtml("\n  \n"));
    }

    public function testRichHtmlRendersATable(): void
    {
        $markdown = "| Code | Objectif |\n|---|---|\n| O1 | Principe agentless |\n| O2 | Préparer un environnement |";

        self::assertSame(
            '<table><thead><tr><th>Code</th><th>Objectif</th></tr></thead>'
            .'<tbody><tr><td>O1</td><td>Principe agentless</td></tr>'
            .'<tr><td>O2</td><td>Préparer un environnement</td></tr></tbody></table>',
            MarkdownRenderer::toRichHtml($markdown),
        );
    }

    public function testRichHtmlKeepsBoldAndInlineCode(): void
    {
        self::assertSame('<p><strong>Durée</strong> : 4 h</p>', MarkdownRenderer::toRichHtml('**Durée** : 4 h'));
        self::assertSame('<p>Lancer <code>ansible all -m ping</code></p>', MarkdownRenderer::toRichHtml('Lancer `ansible all -m ping`'));
        self::assertSame('<p>Une <em>nuance</em></p>', MarkdownRenderer::toRichHtml('Une *nuance*'));
    }

    public function testRichHtmlRendersAFencedCodeBlockVerbatim(): void
    {
        $markdown = "Exemple :\n\n```yaml\nall:\n  hosts: **srv**\n```";

        self::assertSame(
            '<p>Exemple :</p><pre><code>all:'."\n".'  hosts: **srv**</code></pre>',
            MarkdownRenderer::toRichHtml($markdown),
        );
    }

    public function testRichHtmlStillEscapesHtml(): void
    {
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', MarkdownRenderer::toRichHtml('<script>alert(1)</script>'));
        self::assertSame('<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>', MarkdownRenderer::toRichHtml("```\n<b>x</b>\n```"));
    }

    /** A table needs its separator row: three bare pipe lines are a paragraph, not a grid. */
    public function testRichHtmlLeavesPipeLinesAloneWithoutASeparatorRow(): void
    {
        self::assertSame('<p>a | b<br>c | d</p>', MarkdownRenderer::toRichHtml("a | b\nc | d"));
    }

    public function testRichHtmlKeepsParagraphsAndListsWorking(): void
    {
        self::assertSame('<p>Un</p><ul><li>Deux</li></ul>', MarkdownRenderer::toRichHtml("Un\n- Deux"));
        self::assertNull(MarkdownRenderer::toRichHtml('   '));
    }
}
