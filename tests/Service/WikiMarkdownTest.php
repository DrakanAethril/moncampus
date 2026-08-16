<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WikiMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * The archive's two conversions, and the front-matter that makes a `.md` readable on its own.
 *
 * What the tests pin is mostly the *lossy* direction being lossy on purpose - the `.html` beside
 * each `.md` is what the manifest declares authoritative - and the small pieces of judgement around
 * it: what counts as a title in somebody else's notes, and YAML that survives a colon in a title.
 */
class WikiMarkdownTest extends TestCase
{
    public function testAPageBodyBecomesReadableMarkdown(): void
    {
        $markdown = (new WikiMarkdown())->fromHtml('<h2>Adressage IP</h2><p>Un <strong>masque</strong> découpe le réseau.</p>');

        self::assertStringContainsString('## Adressage IP', $markdown);
        self::assertStringContainsString('**masque**', $markdown);
    }

    public function testAnEmptyBodyConvertsToNothingRatherThanToWhitespace(): void
    {
        $markdown = new WikiMarkdown();

        self::assertSame('', $markdown->fromHtml(null));
        self::assertSame('', $markdown->fromHtml('   '));
    }

    public function testGenericMarkdownBecomesHtml(): void
    {
        $html = (new WikiMarkdown())->toHtml("# Titre\n\nUn paragraphe avec du *relief*.");

        self::assertStringContainsString('<h1>Titre</h1>', $html);
        self::assertStringContainsString('<em>relief</em>', $html);
    }

    public function testTheTitleOfAGenericFileIsItsFirstHeading(): void
    {
        $markdown = new WikiMarkdown();

        self::assertSame('Adressage IP', $markdown->titleOf("# Adressage IP\n\ntexte", '01-notes.md'));
    }

    public function testWithoutAHeadingTheFileNameIsTheTitle(): void
    {
        // A folder of notes names its files the way it means its titles - the numbering prefix an
        // export tool adds is not part of that.
        $markdown = new WikiMarkdown();

        self::assertSame('Adressage ip', $markdown->titleOf('texte sans titre', '01-adressage-ip.md'));
        self::assertSame('Notes', $markdown->titleOf('texte', 'notes.md'));
    }

    public function testFrontMatterSurvivesAColonInATitle(): void
    {
        // Unquoted, `title: Réseaux : la suite` is YAML that means something else entirely.
        $front = (new WikiMarkdown())->frontMatter(['title' => 'Réseaux : la suite', 'position' => 3]);

        self::assertStringContainsString('title: "Réseaux : la suite"', $front);
        self::assertStringContainsString('position: 3', $front);
        self::assertStringStartsWith("---\n", $front);
    }

    public function testFrontMatterEscapesAQuoteRatherThanClosingEarly(): void
    {
        $front = (new WikiMarkdown())->frontMatter(['title' => 'Le "vrai" titre']);

        self::assertStringContainsString('title: "Le \"vrai\" titre"', $front);
    }

    public function testFrontMatterIsStrippedBackOffOnImport(): void
    {
        $markdown = new WikiMarkdown();
        $file = $markdown->frontMatter(['title' => 'Titre']).'# Titre'."\n\ntexte";

        self::assertSame("# Titre\n\ntexte", $markdown->withoutFrontMatter($file));
    }

    public function testAFileWithNoFrontMatterIsLeftAlone(): void
    {
        $markdown = new WikiMarkdown();

        self::assertSame("# Titre\n\ntexte", $markdown->withoutFrontMatter("# Titre\n\ntexte"));
        // A horizontal rule at the top is not front-matter, and eating it would delete content.
        self::assertSame("---\n\ntexte", $markdown->withoutFrontMatter("---\n\ntexte"));
    }
}
