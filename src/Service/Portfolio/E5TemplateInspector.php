<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Referential;
use App\Enum\ReferentialBlockRole;
use App\Service\Portfolio\Xlsx\XlsxTemplateException;
use App\Service\Portfolio\Xlsx\XlsxWorkbook;
use App\Service\Rncp\ReferentialLabelMatcher;

/**
 * Checks an uploaded E5 synthesis template **by its landmarks, never by coordinates** (§9, R15).
 *
 * The SIEC publishes a new file with every circulaire; a cell moved one row down must not put a
 * candidate's name in the wrong place, and a template that changed shape must be refused rather
 * than filled wrong. So every value the writer will put somewhere is placed next to a label the
 * inspection found:
 *
 * - the « SESSION » cell, compared with the session expected (a warning when it differs);
 * - the five labels of the head: « NOM et prénom », « N° candidat », « Centre de formation »,
 *   « Option », « Adresse URL du portfolio », and the option boxes on the option's row;
 * - the two headings of the réalisation columns, « Réalisations professionnelles » and « Période »;
 * - one column per competency of the référentiel's synthesis block, **matched by its label**;
 * - the three part titles, and the blank bordered rows under each.
 *
 * A landmark missing is a refusal that names it. What was found is returned as `anchors`, stored
 * on App\Entity\ReferentialTemplate and read by App\Service\Portfolio\E5SynthesisXlsxWriter.
 */
final class E5TemplateInspector
{
    /** @var array<string, string> anchor key => the words that find it */
    private const array LABELS = [
        'name' => 'NOM et prénom',
        'candidate' => 'N° candidat',
        'centre' => 'Centre de formation',
        'option' => 'Option',
        'url' => 'Adresse URL du portfolio',
        'titleHeading' => 'Réalisations professionnelles',
        'periodHeading' => 'Période',
    ];

    /** @var array<int, string> part => the words that find its title */
    private const array PARTS = [
        1 => 'Réalisation en cours de formation',
        2 => 'milieu professionnel en cours de première année',
        3 => 'milieu professionnel en cours de seconde année',
    ];

    /**
     * @return array{anchors: array<string, mixed>, errors: list<array{key: string, detail: string}>, warnings: list<array{key: string, detail: string}>}
     */
    public function inspect(string $path, Referential $referential, ?int $expectedSession): array
    {
        $errors = [];
        $warnings = [];

        try {
            $book = new XlsxWorkbook($path);
        } catch (XlsxTemplateException $exception) {
            return ['anchors' => [], 'errors' => [['key' => $exception->getMessage(), 'detail' => '']], 'warnings' => []];
        }

        $texts = $book->texts();
        $anchors = ['sheet' => $book->sheetPath(), 'labels' => [], 'labelTexts' => [], 'options' => [], 'columns' => [], 'parts' => []];

        // SESSION
        $session = null;
        foreach ($texts as $reference => $text) {
            if (1 === preg_match('/^\s*SESSION\s+(\d{4})\s*$/iu', $text, $match)) {
                $session = ['cell' => $reference, 'value' => (int) $match[1]];
                break;
            }
        }
        if (null === $session) {
            $errors[] = ['key' => 'portfolioTemplateMissingLandmark', 'detail' => 'SESSION'];
        } else {
            $anchors['session'] = $session;
            if (null !== $expectedSession && $session['value'] !== $expectedSession) {
                $warnings[] = ['key' => 'portfolioTemplateSessionMismatch', 'detail' => (string) $session['value']];
            }
        }

        // The head's labels - the first cell *starting* with the words, so « Option » does not
        // catch « Options » of some later note.
        foreach (self::LABELS as $key => $words) {
            $found = null;
            foreach ($texts as $reference => $text) {
                if (str_starts_with(ReferentialLabelMatcher::normalize($text), ReferentialLabelMatcher::normalize($words))
                    || ('titleHeading' === $key && ReferentialLabelMatcher::contains($text, $words))) {
                    $found = $reference;
                    break;
                }
            }

            if (null === $found) {
                $errors[] = ['key' => 'portfolioTemplateMissingLandmark', 'detail' => $words];
            } else {
                $anchors['labels'][$key] = $found;
                $anchors['labelTexts'][$key] = $texts[$found];
            }
        }

        // Option boxes: on the « Option » row, the cells naming one of the référentiel's options.
        if (isset($anchors['labels']['option'])) {
            [, $optionRow] = XlsxWorkbook::split($anchors['labels']['option']);
            foreach ($referential->getBlocks() as $block) {
                if (ReferentialBlockRole::Showcase !== $block->getRole()) {
                    continue;
                }
                foreach ($block->getOptions() as $option) {
                    foreach ($texts as $reference => $text) {
                        if (XlsxWorkbook::split($reference)[1] === $optionRow && ReferentialLabelMatcher::contains($text, $option->getShortName())) {
                            $anchors['options'][$option->getShortName()] = ['cell' => $reference, 'text' => $text];
                        }
                    }
                    if (!isset($anchors['options'][$option->getShortName()])) {
                        $errors[] = ['key' => 'portfolioTemplateMissingLandmark', 'detail' => '▢ '.$option->getShortName()];
                    }
                }
            }
        }

        // One column per competency of the synthesis block, by label.
        $block = $referential->getSynthesisBlock();
        if (null === $block) {
            $errors[] = ['key' => 'portfolioTemplateNoSynthesisBlock', 'detail' => ''];
        } else {
            foreach ($block->getCompetencies() as $competency) {
                $found = null;
                foreach ($texts as $reference => $text) {
                    if (ReferentialLabelMatcher::same($text, $competency->getLabel())) {
                        $found = $reference;
                        break;
                    }
                }
                if (null === $found) {
                    $errors[] = ['key' => 'portfolioTemplateMissingColumn', 'detail' => $competency->getLabel()];
                } else {
                    $anchors['columns'][(string) $competency->getId()] = ['column' => XlsxWorkbook::split($found)[0], 'label' => $competency->getLabel()];
                }
            }
        }

        // The three parts and their blank rows: from the title's next row, as long as the row is
        // drawn (its first cell carries a border) and is not the next title.
        $titleRows = [];
        foreach (self::PARTS as $number => $words) {
            foreach ($texts as $reference => $text) {
                if (ReferentialLabelMatcher::contains($text, $words)) {
                    $titleRows[$number] = XlsxWorkbook::split($reference)[1];
                    break;
                }
            }
            if (!isset($titleRows[$number])) {
                $errors[] = ['key' => 'portfolioTemplateMissingLandmark', 'detail' => $words];
            }
        }

        if (3 === \count($titleRows) && isset($anchors['labels']['titleHeading'])) {
            $titleColumn = XlsxWorkbook::split($anchors['labels']['titleHeading'])[0];
            $rows = $book->rowNumbers();
            foreach ($titleRows as $number => $titleRow) {
                $first = $titleRow + 1;
                $last = null;
                foreach ($rows as $row) {
                    if ($row < $first) {
                        continue;
                    }
                    if (\in_array($row, $titleRows, true) || !$book->isBordered($titleColumn.$row) || isset($texts[$titleColumn.$row])) {
                        break;
                    }
                    $last = $row;
                }

                if (null === $last) {
                    $errors[] = ['key' => 'portfolioTemplateNoRowsInPart', 'detail' => self::PARTS[$number]];
                } else {
                    $anchors['parts'][(string) $number] = ['title' => $titleRow, 'first' => $first, 'last' => $last];
                }
            }
        }

        return ['anchors' => $anchors, 'errors' => $errors, 'warnings' => $warnings];
    }
}
