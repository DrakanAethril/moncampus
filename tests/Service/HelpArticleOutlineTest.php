<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HelpArticleOutline;
use PHPUnit\Framework\TestCase;

/**
 * "Sur cette page" is not a field an admin fills in: it is read back from the article's own level-2
 * headings, so an article whose sections change cannot end up with a stale sommaire. The anchors it
 * links to are stamped onto the same HTML in the same pass, which is what makes them agree.
 */
class HelpArticleOutlineTest extends TestCase
{
    public function testListsLevelTwoHeadingsAndStampsAnAnchorOnEachOfThem(): void
    {
        $outline = new HelpArticleOutline();

        $result = $outline->build('<p>Chapeau</p><h2>Ouvrir la création</h2><p>…</p><h2>Publier</h2>');

        self::assertSame([
            ['id' => 'ouvrir-la-creation', 'title' => 'Ouvrir la création'],
            ['id' => 'publier', 'title' => 'Publier'],
        ], $result['entries']);
        self::assertStringContainsString('<h2 id="ouvrir-la-creation">Ouvrir la création</h2>', $result['html']);
        self::assertStringContainsString('<h2 id="publier">Publier</h2>', $result['html']);
    }

    public function testKeepsAnAnchorTheAuthorWroteHerself(): void
    {
        $outline = new HelpArticleOutline();

        $result = $outline->build('<h2 id="etape-1">Première étape</h2>');

        self::assertSame([['id' => 'etape-1', 'title' => 'Première étape']], $result['entries']);
        self::assertStringContainsString('id="etape-1"', $result['html']);
    }

    public function testDeduplicatesTwoHeadingsThatWouldShareAnAnchor(): void
    {
        $outline = new HelpArticleOutline();

        $result = $outline->build('<h2>Publier</h2><h2>Publier</h2>');

        self::assertSame(['publier', 'publier-2'], array_column($result['entries'], 'id'));
    }

    public function testReadsTheHeadingTextThroughItsInlineMarkup(): void
    {
        $outline = new HelpArticleOutline();

        $result = $outline->build('<h2><strong>Choisir</strong> les destinataires</h2>');

        self::assertSame([['id' => 'choisir-les-destinataires', 'title' => 'Choisir les destinataires']], $result['entries']);
    }

    public function testAnArticleWithoutHeadingsHasNoOutline(): void
    {
        $outline = new HelpArticleOutline();

        $result = $outline->build('<p>Un seul paragraphe.</p>');

        self::assertSame([], $result['entries']);
        self::assertSame('<p>Un seul paragraphe.</p>', $result['html']);
    }

    public function testAnEmptyBodyIsHandledLikeAnEmptyArticle(): void
    {
        $outline = new HelpArticleOutline();

        $result = $outline->build(null);

        self::assertSame([], $result['entries']);
        self::assertSame('', $result['html']);
    }
}
