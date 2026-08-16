<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WikiHeadingShift;
use PHPUnit\Framework\TestCase;

/**
 * What makes the exported PDF one document rather than a stack of pages.
 *
 * Every wiki page is written as if it were the only one - its author starts at h1 or h2 without
 * knowing how deep the page sits. Printed one after another that gives a document with a dozen
 * competing h1s and no outline at all. Shifting each page's headings by its depth is what turns the
 * tree into a single coherent hierarchy, and the cap at h6 is what stops a deep branch from
 * producing markup that does not exist.
 */
class WikiHeadingShiftTest extends TestCase
{
    public function testARootPageIsLeftAlone(): void
    {
        $shift = new WikiHeadingShift();

        self::assertSame('<h1>Titre</h1><p>x</p>', $shift->apply('<h1>Titre</h1><p>x</p>', 0));
    }

    public function testEachLevelOfDepthDemotesEveryHeading(): void
    {
        $shift = new WikiHeadingShift();

        self::assertSame('<h3>A</h3><h4>B</h4>', $shift->apply('<h2>A</h2><h3>B</h3>', 1));
        self::assertSame('<h4>A</h4><h5>B</h5>', $shift->apply('<h2>A</h2><h3>B</h3>', 2));
    }

    public function testAttributesAndInnerMarkupSurviveTheDemotion(): void
    {
        $shift = new WikiHeadingShift();

        self::assertSame(
            '<h3 id="plan" class="x"><strong>Plan</strong></h3>',
            $shift->apply('<h2 id="plan" class="x"><strong>Plan</strong></h2>', 1),
        );
    }

    public function testHeadingsNeverGoPastH6(): void
    {
        // There is no h7: a page eight levels down would otherwise print markup no browser and no
        // PDF renderer understands.
        $shift = new WikiHeadingShift();

        self::assertSame('<h6>A</h6><h6>B</h6>', $shift->apply('<h5>A</h5><h6>B</h6>', 2));
        self::assertSame('<h6>A</h6>', $shift->apply('<h1>A</h1>', 9));
    }

    public function testNothingElseIsTouched(): void
    {
        $shift = new WikiHeadingShift();
        $html = '<p>Un h2 dans le texte</p><div class="cm-callout"><p>x</p></div><pre><code>h1 { color: red }</code></pre>';

        self::assertSame($html, $shift->apply($html, 2));
    }

    public function testAnEmptyBodyStaysEmpty(): void
    {
        $shift = new WikiHeadingShift();

        self::assertSame('', $shift->apply(null, 3));
        self::assertSame('', $shift->apply('', 3));
    }

    public function testTheLevelAPagesOwnTitleIsPrintedAt(): void
    {
        $shift = new WikiHeadingShift();

        // A root page's title is an h1, one level down an h2, and so on to the h6 cap.
        self::assertSame(1, $shift->titleLevel(0));
        self::assertSame(2, $shift->titleLevel(1));
        self::assertSame(6, $shift->titleLevel(5));
        self::assertSame(6, $shift->titleLevel(12));
    }

    public function testABodyNestsUnderItsOwnTitleRatherThanBesideIt(): void
    {
        $shift = new WikiHeadingShift();

        // Shifting the body by the plain depth was tried first and read wrong in the produced PDF:
        // a root page printed its title as h1 and then the author's own h1 as another h1 - the very
        // "competing h1s" the shift exists to prevent, one page at a time.
        self::assertSame($shift->titleLevel(0), $shift->bodyOffset(0));
        self::assertSame('<h2>Écrit en h1</h2>', $shift->apply('<h1>Écrit en h1</h1>', $shift->bodyOffset(0)));
        self::assertSame('<h3>Écrit en h1</h3>', $shift->apply('<h1>Écrit en h1</h1>', $shift->bodyOffset(1)));
    }
}
