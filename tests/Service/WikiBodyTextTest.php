<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HtmlPlainText;
use App\Service\WikiBodyText;
use PHPUnit\Framework\TestCase;

/**
 * The de-tagged copy of a page's body, rebuilt on every save.
 *
 * It is a column of its own rather than a LIKE over the HTML for two reasons the tests below pin:
 * searching for "table" must not match the HTML tag, and words separated only by a block boundary
 * must not run into each other ("<p>Réseaux</p><p>Sécurité</p>" is two words, not "RéseauxSécurité").
 */
class WikiBodyTextTest extends TestCase
{
    public function testTagsAreDroppedAndTheirTextKept(): void
    {
        self::assertSame('Adressage IP', (new WikiBodyText(new HtmlPlainText()))->fromHtml('<h1>Adressage</h1><p><strong>IP</strong></p>'));
    }

    public function testABlockBoundaryIsAWordBoundary(): void
    {
        self::assertSame('Réseaux Sécurité', (new WikiBodyText(new HtmlPlainText()))->fromHtml('<p>Réseaux</p><p>Sécurité</p>'));
    }

    public function testTheTagNameItselfIsNeverIndexed(): void
    {
        // The whole reason this column exists: "table" must find the word, not the markup.
        self::assertSame('Une cellule', (new WikiBodyText(new HtmlPlainText()))->fromHtml('<table><tr><td>Une cellule</td></tr></table>'));
    }

    public function testScriptAndStyleContentIsDroppedRatherThanIndexed(): void
    {
        self::assertSame('Visible', (new WikiBodyText(new HtmlPlainText()))->fromHtml('<style>.a{color:red}</style><p>Visible</p><script>alert(1)</script>'));
    }

    public function testEntitiesAreDecodedSoASearchMatchesWhatTheReaderSees(): void
    {
        self::assertSame('R&D « test »', (new WikiBodyText(new HtmlPlainText()))->fromHtml('<p>R&amp;D &laquo;&nbsp;test&nbsp;&raquo;</p>'));
    }

    public function testAnEmptyOrBlankBodyProducesNothingToIndex(): void
    {
        $text = new WikiBodyText(new HtmlPlainText());

        self::assertNull($text->fromHtml(null));
        self::assertNull($text->fromHtml(''));
        self::assertNull($text->fromHtml('<p>&nbsp;</p>'));
    }
}
