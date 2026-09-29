<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Service\Portfolio\Xlsx\XlsxWorkbook;

/**
 * Fills the official E5 template (annexe VI-1) with a synthesis table - **filled, never redrawn**
 * (§3, decision 5; §11).
 *
 * Values go where App\Service\Portfolio\E5TemplateInspector found their labels, and they go the
 * way candidates are told to write them: « in the same cell as the label » (« NOM et prénom :
 * MARTIN Léa »). The option box is ticked by replacing its « ▢ » with « ☒ ». Each réalisation is a
 * row of its part - its title and its documents in the first column, its period in the second, an
 * « X » under each competency retained.
 *
 * A part longer than the template's blank rows gets more rows, copied from its first, and everything
 * below moves down - merged ranges and print area included.
 *
 * Only validated work is written: a SynthesisTable built with its pending réalisations is refused.
 */
final class E5SynthesisXlsxWriter
{
    public const string MARK = 'X';

    /**
     * @param array<string, mixed> $anchors as the inspection stored them
     *
     * @return string the bytes of the filled .xlsx
     */
    public function write(string $templatePath, array $anchors, SynthesisTable $table): string
    {
        if ($table->includesPending) {
            throw new \LogicException('The official table is written from validated work only.');
        }

        $book = new XlsxWorkbook($templatePath);
        $labels = self::stringMap($anchors['labels'] ?? null);
        $labelTexts = self::stringMap($anchors['labelTexts'] ?? null);

        $values = [
            'name' => $table->studentName,
            'candidate' => $table->candidateNumber ?? '',
            'centre' => $table->trainingCentre ?? '',
            'url' => $table->portfolioUrl ?? '',
        ];
        foreach ($values as $key => $value) {
            if (isset($labels[$key]) && '' !== $value) {
                $book->setText($labels[$key], rtrim($labelTexts[$key] ?? '').' '.$value);
            }
        }

        $options = \is_array($anchors['options'] ?? null) ? $anchors['options'] : [];
        foreach ($table->options as $option) {
            $cell = $options[$option['label']] ?? null;
            if (!$option['checked'] || !\is_array($cell) || !\is_string($cell['cell'] ?? null) || !\is_string($cell['text'] ?? null)) {
                continue;
            }
            $text = str_contains($cell['text'], '▢') ? str_replace('▢', '☒', $cell['text']) : '☒ '.ltrim($cell['text']);
            $book->setText($cell['cell'], $text);
        }

        $titleColumn = isset($labels['titleHeading']) ? XlsxWorkbook::split($labels['titleHeading'])[0] : 'A';
        $periodColumn = isset($labels['periodHeading']) ? XlsxWorkbook::split($labels['periodHeading'])[0] : 'B';
        $columns = [];
        foreach (\is_array($anchors['columns'] ?? null) ? $anchors['columns'] : [] as $competencyId => $column) {
            if (\is_array($column) && \is_string($column['column'] ?? null)) {
                $columns[(int) $competencyId] = $column['column'];
            }
        }

        // Parts from the bottom up: inserting rows in part 1 would move parts 2 and 3.
        $parts = \is_array($anchors['parts'] ?? null) ? $anchors['parts'] : [];
        krsort($parts);
        foreach ($parts as $number => $part) {
            if (!\is_array($part) || !\is_int($part['first'] ?? null) || !\is_int($part['last'] ?? null)) {
                continue;
            }

            $rows = $table->sections[(int) $number] ?? [];
            $available = $part['last'] - $part['first'] + 1;
            $book->insertRowsAfter($part['first'], \count($rows) - $available);

            foreach ($rows as $index => $row) {
                $line = $part['first'] + $index;
                $book->setText($titleColumn.$line, '' === $row['documents'] ? $row['title'] : $row['title']."\n".$row['documents']);
                $book->setText($periodColumn.$line, $row['period']);

                foreach ($row['cells'] as $competencyId => $mark) {
                    if (SynthesisTable::RETAINED === $mark && isset($columns[$competencyId])) {
                        $book->setText($columns[$competencyId].$line, self::MARK);
                    }
                }
            }
        }

        return $book->save();
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        $map = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_string($item)) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
    }
}
