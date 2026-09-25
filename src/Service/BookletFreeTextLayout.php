<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Lays a free text written in HugeRTE out as sections of the Livret de l'alternant: the modalités
 * de contrat become sections 5, 6, 7... of chapter I, whatever the editor made of their headings.
 *
 * Done on reading, never on saving - the text in the database is left exactly as it was written,
 * and a text written before this existed is laid out the same way. Three decisions:
 *
 *  - the level of a heading is RELATIVE: the highest level present in the text is a numbered
 *    section, anything below it a subtitle. A text written in h1/h2 and one written in h2/h3 give
 *    the same booklet, so nobody has to know which heading the booklet expects;
 *  - the numbers are the booklet's, not the author's. A number typed at the head of a section
 *    heading (« 5. », « 6 – », « VII) ») is dropped and the section gets its place in the order, so
 *    adding or moving a section never means renumbering by hand;
 *  - the editor's own styling of a heading is dropped with it: the booklet has one look for a
 *    section heading, and a heading in red 30px would not be one.
 */
final class BookletFreeTextLayout
{
    /**
     * A section number typed by hand: one or two figures or a Roman numeral, then a separator. The
     * separator is what tells « 5. Modalités » from « 35 heures par semaine ».
     */
    private const string TYPED_NUMBER = '/^(?:\d{1,2}|[IVXLC]{1,5})\s*[.)\-–—:]\s*/u';

    /**
     * @param string $anchorPrefix the id of section N is this prefix followed by N
     *
     * @return BookletFreeText|null null when there is nothing to show
     */
    public function lay(?string $html, int $firstNumber, string $anchorPrefix): ?BookletFreeText
    {
        if (null === $html || '' === self::plain(strip_tags(html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')))) {
            return null;
        }

        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', \LIBXML_NOERROR);
        $body = $document->body ?? throw new \LogicException('An HTML document always has a body.');

        $headings = [];
        foreach ($body->querySelectorAll('h1, h2, h3, h4, h5, h6') as $heading) {
            if ('' === self::plain($heading->textContent ?? '')) {
                $heading->remove();
            } else {
                $headings[] = $heading;
            }
        }

        $sectionLevel = [] === $headings ? 0 : min(array_map(self::level(...), $headings));
        $number = $firstNumber;
        $sections = [];

        foreach ($headings as $heading) {
            $label = self::plain($heading->textContent ?? '');

            if (self::level($heading) === $sectionLevel) {
                // A heading that is nothing but a number keeps it rather than becoming empty.
                $label = self::plain((string) preg_replace(self::TYPED_NUMBER, '', $label)) ?: $label;
                $anchor = $anchorPrefix.$number;
                $replacement = $document->createElement('h2');
                $replacement->setAttribute('class', 'lv-h2');
                $replacement->setAttribute('id', $anchor);
                $replacement->textContent = $number.'. '.$label;
                $sections[] = ['number' => $number, 'label' => $label, 'anchor' => $anchor];
                ++$number;
            } else {
                $replacement = $document->createElement('h3');
                $replacement->setAttribute('class', 'lv-h3');
                $replacement->textContent = $label;
            }

            $heading->replaceWith($replacement);
        }

        return new BookletFreeText($body->innerHTML, $sections);
    }

    private static function level(\Dom\Element $heading): int
    {
        return (int) substr($heading->localName, 1);
    }

    /** Text as a reader sees it: no-break spaces are spaces, runs of blanks are one. */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
