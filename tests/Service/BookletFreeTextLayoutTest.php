<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BookletFreeText;
use App\Service\BookletFreeTextLayout;
use PHPUnit\Framework\TestCase;

/**
 * The modalités de contrat are written in an editor that knows nothing of the Livret, and the text
 * already in the database is not touched: whatever heading levels it was written with, it has to
 * come out numbered, styled and listed like the booklet's own sections.
 */
class BookletFreeTextLayoutTest extends TestCase
{
    public function testTheTopHeadingLevelBecomesNumberedSectionsFromTheGivenNumber(): void
    {
        $text = $this->lay('<h2>Modalités</h2><p>Un.</p><h2>Durée de travail</h2><p>Deux.</p>');

        self::assertSame([
            ['number' => 5, 'label' => 'Modalités', 'anchor' => 'section-i-5'],
            ['number' => 6, 'label' => 'Durée de travail', 'anchor' => 'section-i-6'],
        ], $text->sections);
        self::assertSame(
            '<h2 class="lv-h2" id="section-i-5">5. Modalités</h2><p>Un.</p><h2 class="lv-h2" id="section-i-6">6. Durée de travail</h2><p>Deux.</p>',
            $text->html,
        );
    }

    /** The level is relative: a text written in h1/h2 and one written in h2/h3 give the same booklet. */
    public function testTheLevelIsReadRelativeToTheHighestOnePresent(): void
    {
        $fromH1 = $this->lay('<h1>Titre</h1><h2>Sous-titre</h2><p>x</p>');
        $fromH2 = $this->lay('<h2>Titre</h2><h3>Sous-titre</h3><p>x</p>');

        self::assertSame($fromH1->html, $fromH2->html);
        self::assertSame('<h2 class="lv-h2" id="section-i-5">5. Titre</h2><h3 class="lv-h3">Sous-titre</h3><p>x</p>', $fromH1->html);
    }

    public function testSubtitlesStayOutOfTheSommaire(): void
    {
        $text = $this->lay('<h1>Titre</h1><h2>Sous-titre</h2><h3>Plus bas encore</h3>');

        self::assertCount(1, $text->sections);
        self::assertStringContainsString('<h3 class="lv-h3">Plus bas encore</h3>', $text->html);
    }

    /** Text already written with its own numbers must not come out as « 5. 5. Titre ». */
    public function testANumberTypedByHandIsReplaced(): void
    {
        $text = $this->lay('<h2>5. Modalités</h2><h2>6 – Durée</h2><h2>VII) Fonctionnement</h2><h2>8 : Livret</h2>');

        self::assertSame(['Modalités', 'Durée', 'Fonctionnement', 'Livret'], array_column($text->sections, 'label'));
    }

    /** A heading that merely starts with a figure is a title, not a number. */
    public function testAFigureThatIsPartOfTheTitleIsKept(): void
    {
        $text = $this->lay('<h2>35 heures par semaine</h2><h2>2024 et après</h2>');

        self::assertSame(['35 heures par semaine', '2024 et après'], array_column($text->sections, 'label'));
    }

    public function testTheEditorsOwnStylingOfAHeadingIsDropped(): void
    {
        $text = $this->lay('<h2 style="color: red; font-size: 30px;"><strong>Titre</strong></h2><h3 style="text-align: center;">Sous</h3>');

        self::assertSame('<h2 class="lv-h2" id="section-i-5">5. Titre</h2><h3 class="lv-h3">Sous</h3>', $text->html);
    }

    public function testAnEmptyHeadingIsNotASection(): void
    {
        $text = $this->lay('<h2>&nbsp;</h2><h2>Vrai titre</h2>');

        self::assertSame([['number' => 5, 'label' => 'Vrai titre', 'anchor' => 'section-i-5']], $text->sections);
        self::assertSame('<h2 class="lv-h2" id="section-i-5">5. Vrai titre</h2>', $text->html);
    }

    public function testTextBeforeTheFirstHeadingIsKeptUnnumbered(): void
    {
        $text = $this->lay('<p>Préambule.</p><h2>Titre</h2>');

        self::assertStringStartsWith('<p>Préambule.</p>', $text->html);
        self::assertCount(1, $text->sections);
    }

    public function testATextWithoutHeadingsIsShownWithoutSections(): void
    {
        $text = $this->lay('<p>Seulement du texte.</p>');

        self::assertSame([], $text->sections);
        self::assertSame('<p>Seulement du texte.</p>', $text->html);
    }

    /** Nothing to show is nothing shown: no section, no line in the sommaire. */
    public function testABlankTextIsNothing(): void
    {
        self::assertNull((new BookletFreeTextLayout())->lay('', 5, 'section-i-'));
        self::assertNull((new BookletFreeTextLayout())->lay('<p>&nbsp;</p><p><br></p>', 5, 'section-i-'));
        self::assertNull((new BookletFreeTextLayout())->lay(null, 5, 'section-i-'));
    }

    public function testTheRestOfTheMarkupIsLeftAlone(): void
    {
        $html = '<h2>Titre</h2><ul><li>Un <strong>point</strong></li></ul><table><tbody><tr><td>x</td></tr></tbody></table>';

        self::assertSame(
            '<h2 class="lv-h2" id="section-i-5">5. Titre</h2><ul><li>Un <strong>point</strong></li></ul><table><tbody><tr><td>x</td></tr></tbody></table>',
            $this->lay($html)->html,
        );
    }

    private function lay(string $html): BookletFreeText
    {
        $text = (new BookletFreeTextLayout())->lay($html, 5, 'section-i-');
        self::assertNotNull($text);

        return $text;
    }
}
