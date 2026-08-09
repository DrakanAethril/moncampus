<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads one worksheet of an .xlsx as plain rows of strings.
 *
 * Hand-rolled on ZipArchive + SimpleXML rather than pulled from a spreadsheet library: the only
 * xlsx this app ever reads is a Kahoot report (App\Service\KahootXlsxImporter), a flat grid of text
 * with no formulas, no dates and no styling to interpret. A full reader would be a dependency
 * shipped into the production image for a single upload screen.
 *
 * What it does handle, because a real file needs it: the shared-string table (where Excel parks
 * every repeated text), rich-text runs inside a shared string, inline strings, and sparse rows -
 * a cell's column comes from its own `r` reference, never from its position among its siblings.
 */
class XlsxSheetReader
{
    /**
     * Sheet names in workbook order.
     *
     * @return list<string>
     *
     * @throws XlsxReadException
     */
    public function sheetNames(string $path): array
    {
        $zip = $this->open($path);

        try {
            $names = [];
            foreach ($this->workbookSheets($zip) as $sheet) {
                $names[] = $sheet['name'];
            }

            return $names;
        } finally {
            $zip->close();
        }
    }

    /**
     * One sheet's cells, row by row, every value as a string.
     *
     * @return list<list<string>>
     *
     * @throws XlsxReadException
     */
    public function rows(string $path, string $sheetName): array
    {
        $zip = $this->open($path);

        try {
            $target = null;
            foreach ($this->workbookSheets($zip) as $sheet) {
                if ($sheet['name'] === $sheetName) {
                    $target = $sheet['path'];
                }
            }

            if (null === $target) {
                throw new XlsxReadException('quizImportKahootMissingSheetMessage', ['%sheet%' => $sheetName]);
            }

            return $this->readSheet($zip, $target, $this->sharedStrings($zip));
        } finally {
            $zip->close();
        }
    }

    private function open(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new XlsxReadException('quizImportKahootUnreadableMessage');
        }

        return $zip;
    }

    /**
     * Sheet name => worksheet part, resolved through the workbook's relationships. The parts are
     * NOT named after their order (sheet22.xml can be the fourth tab), so the r:id indirection is
     * the only reliable way across.
     *
     * @return list<array{name: string, path: string}>
     */
    private function workbookSheets(\ZipArchive $zip): array
    {
        $workbook = $this->xml($zip, 'xl/workbook.xml');
        $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');

        $targets = [];
        foreach ($rels->children() as $relationship) {
            $targets[(string) $relationship['Id']] = ltrim((string) $relationship['Target'], '/');
        }

        $sheets = [];
        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $id = (string) $sheet->attributes('r', true)['id'];
            $target = $targets[$id] ?? null;
            if (null === $target) {
                continue;
            }

            $sheets[] = [
                'name' => (string) $sheet['name'],
                'path' => str_starts_with($target, 'xl/') ? $target : 'xl/'.$target,
            ];
        }

        return $sheets;
    }

    /** @return list<string> */
    private function sharedStrings(\ZipArchive $zip): array
    {
        if (false === $zip->locateName('xl/sharedStrings.xml')) {
            return [];
        }

        $strings = [];
        foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si ?? [] as $item) {
            // A styled string is split into runs, each with its own <t> - the value is all of them
            // joined, and taking only the first would silently truncate it.
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $node) {
                $text .= (string) $node;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param list<string> $sharedStrings
     *
     * @return list<list<string>>
     */
    private function readSheet(\ZipArchive $zip, string $path, array $sharedStrings): array
    {
        $sheet = $this->xml($zip, $path);

        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $cells = [];
            $width = 0;

            foreach ($row->c ?? [] as $cell) {
                $index = $this->columnIndex((string) $cell['r']);
                $cells[$index] = $this->cellValue($cell, $sharedStrings);
                $width = max($width, $index + 1);
            }

            // Filled back to a dense list: the consumer indexes by column position, and a skipped
            // empty cell would shift every value after it one column to the left.
            $dense = [];
            for ($i = 0; $i < $width; ++$i) {
                $dense[] = $cells[$i] ?? '';
            }

            $rows[] = $dense;
        }

        return $rows;
    }

    /** @param list<string> $sharedStrings */
    private function cellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];

        if ('s' === $type) {
            return $sharedStrings[(int) $cell->v] ?? '';
        }

        if ('inlineStr' === $type) {
            $text = '';
            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $node) {
                $text .= (string) $node;
            }

            return $text;
        }

        return (string) $cell->v;
    }

    /** "BC12" => 54: the letters are a base-26 number, not a single character. */
    private function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($reference), $matches);

        $index = 0;
        foreach (str_split($matches[1] ?? 'A') as $letter) {
            $index = $index * 26 + (\ord($letter) - 64);
        }

        return $index - 1;
    }

    private function xml(\ZipArchive $zip, string $path): \SimpleXMLElement
    {
        $content = $zip->getFromName($path);
        if (false === $content) {
            throw new XlsxReadException('quizImportKahootUnreadableMessage');
        }

        $xml = @simplexml_load_string($content);
        if (false === $xml) {
            throw new XlsxReadException('quizImportKahootUnreadableMessage');
        }

        return $xml;
    }
}
