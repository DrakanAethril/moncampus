<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HtmlPlainText;
use PHPUnit\Framework\TestCase;

/**
 * Flattening HugeRTE's HTML to the text a screen shows in one line.
 *
 * The case that brought this here: Twig's own |striptags removes the tags and leaves the entities
 * alone, so « Séance » stored as "S&eacute;ance" reaches the browser as "S&amp;eacute;ance" and is
 * read out loud as "S&eacute;ance". Decoding has to happen before the template escapes anything,
 * which is why this is a service and a filter rather than a chain of Twig filters.
 */
class HtmlPlainTextTest extends TestCase
{
    private HtmlPlainText $plain;

    protected function setUp(): void
    {
        $this->plain = new HtmlPlainText();
    }

    public function testEntitiesAreDecodedRatherThanShownAsTheirCode(): void
    {
        self::assertSame('Séance découverte', $this->plain->fromHtml('<p>S&eacute;ance d&eacute;couverte</p>'));
    }

    public function testNumericEntitiesToo(): void
    {
        self::assertSame('« Réseaux »', $this->plain->fromHtml('<p>&#171;&nbsp;R&#233;seaux&nbsp;&#187;</p>'));
    }

    public function testTagsAreDroppedAndTheirTextKept(): void
    {
        self::assertSame('Adressage IP', $this->plain->fromHtml('<h1>Adressage</h1><p><strong>IP</strong></p>'));
    }

    public function testABlockBoundaryIsAWordBoundary(): void
    {
        // Without this, two paragraphs read as one run-on word in the preview line.
        self::assertSame('Réseaux Sécurité', $this->plain->fromHtml('<p>Réseaux</p><p>Sécurité</p>'));
    }

    public function testScriptAndStyleCarryNoText(): void
    {
        self::assertSame('Visible', $this->plain->fromHtml('<style>.a{color:red}</style><p>Visible</p><script>alert(1)</script>'));
    }

    public function testANonBreakingSpaceBecomesAnOrdinaryOne(): void
    {
        // French typography puts one before every ':' - a preview must not carry them as invisible
        // oddities that break the truncation and copy/paste badly.
        self::assertSame('Objectif : découvrir', $this->plain->fromHtml('<p>Objectif&nbsp;: d&eacute;couvrir</p>'));
    }

    public function testAnEmptiedEditorAnswersNullRatherThanAnInvisibleString(): void
    {
        // What HugeRTE leaves behind when a teacher types something then deletes it - the screen
        // has to fall back on its « rien pour l'instant » wording, not print a blank line.
        self::assertNull($this->plain->fromHtml('<p>&nbsp;</p>'));
    }

    public function testNothingAtAllAnswersNull(): void
    {
        self::assertNull($this->plain->fromHtml(null));
        self::assertNull($this->plain->fromHtml('   '));
    }

    public function testMarkupWrittenAsEntitiesStaysText(): void
    {
        // Decoded, never re-parsed: the caller escapes it as the text it is.
        self::assertSame('<script>alert(1)</script>', $this->plain->fromHtml('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'));
    }
}
