<?php

declare(strict_types=1);

namespace App\Service\Portfolio\Xlsx;

/**
 * The first worksheet of an .xlsx, opened as a DOM - enough to read the official E5 template by its
 * landmarks and to write into it, without a spreadsheet library in the production image (the same
 * choice as App\Service\XlsxSheetReader, ZipArchive and the XML the format is made of).
 *
 * What it understands: shared strings (rich runs included), inline strings, the cell styles'
 * borders (to tell a table row from the blank rows under it), merged ranges and the print area.
 * What it does not: formulas, dates, other sheets - the template has none that matter.
 */
final class XlsxWorkbook
{
    public const string NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private \DOMDocument $sheet;

    private \DOMXPath $xpath;

    private string $sheetPath;

    private ?\DOMDocument $workbook = null;

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var array<int, bool> style index => has a border */
    private array $bordered = [];

    /**
     * @throws XlsxTemplateException
     */
    public function __construct(private readonly string $path)
    {
        $zip = new \ZipArchive();

        if (true !== $zip->open($path)) {
            throw new XlsxTemplateException('portfolioTemplateNotXlsxError');
        }

        try {
            $this->sheetPath = $this->firstSheetPath($zip);
            $sheetXml = $zip->getFromName($this->sheetPath);
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            $stylesXml = $zip->getFromName('xl/styles.xml');
        } finally {
            $zip->close();
        }

        if (!\is_string($sheetXml)) {
            throw new XlsxTemplateException('portfolioTemplateNotXlsxError');
        }

        $this->sheet = self::dom($sheetXml);
        $this->xpath = new \DOMXPath($this->sheet);
        $this->xpath->registerNamespace('m', self::NS);

        if (\is_string($workbookXml)) {
            $this->workbook = self::dom($workbookXml);
        }

        if (\is_string($sharedXml)) {
            $shared = self::dom($sharedXml);
            foreach ($shared->getElementsByTagNameNS(self::NS, 'si') as $si) {
                $text = '';
                foreach ($si->getElementsByTagNameNS(self::NS, 't') as $t) {
                    $text .= $t->textContent;
                }
                $this->sharedStrings[] = $text;
            }
        }

        if (\is_string($stylesXml)) {
            $this->readBorders(self::dom($stylesXml));
        }
    }

    public function sheetPath(): string
    {
        return $this->sheetPath;
    }

    /**
     * Every non-empty cell's text, by reference.
     *
     * @return array<string, string>
     */
    public function texts(): array
    {
        $texts = [];
        foreach ($this->xpath->query('//m:sheetData/m:row/m:c') ?: [] as $cell) {
            if ($cell instanceof \DOMElement) {
                $text = $this->cellText($cell);
                if ('' !== trim($text)) {
                    $texts[$cell->getAttribute('r')] = $text;
                }
            }
        }

        return $texts;
    }

    /** @return list<int> the row numbers present in the sheet, ascending */
    public function rowNumbers(): array
    {
        $rows = [];
        foreach ($this->xpath->query('//m:sheetData/m:row') ?: [] as $row) {
            if ($row instanceof \DOMElement) {
                $rows[] = (int) $row->getAttribute('r');
            }
        }
        sort($rows);

        return $rows;
    }

    /** Does the cell at this reference carry a border - is it inside the drawn table? */
    public function isBordered(string $reference): bool
    {
        $cell = $this->cell($reference);

        return null !== $cell && ($this->bordered[(int) $cell->getAttribute('s')] ?? false);
    }

    public function cell(string $reference): ?\DOMElement
    {
        return $this->first('//m:sheetData/m:row/m:c[@r="'.$reference.'"]');
    }

    public function row(int $number): ?\DOMElement
    {
        return $this->first('//m:sheetData/m:row[@r="'.$number.'"]');
    }

    public function cellText(\DOMElement $cell): string
    {
        $type = $cell->getAttribute('t');

        if ('inlineStr' === $type) {
            $text = '';
            foreach ($cell->getElementsByTagNameNS(self::NS, 't') as $t) {
                $text .= $t->textContent;
            }

            return $text;
        }

        $value = $cell->getElementsByTagNameNS(self::NS, 'v')->item(0)->textContent ?? '';

        return 's' === $type ? ($this->sharedStrings[(int) $value] ?? '') : $value;
    }

    /** Writes a text into a cell - creating it in its row if needed - as an inline string. */
    public function setText(string $reference, string $text): void
    {
        [$column, $rowNumber] = self::split($reference);
        $row = $this->row($rowNumber) ?? throw new XlsxTemplateException('portfolioTemplateWriteError');
        $cell = $this->cell($reference);

        if (null === $cell) {
            $cell = $this->sheet->createElementNS(self::NS, 'c');
            $cell->setAttribute('r', $reference);
            $before = null;
            foreach ($row->getElementsByTagNameNS(self::NS, 'c') as $existing) {
                if (self::columnIndex(self::split($existing->getAttribute('r'))[0]) > self::columnIndex($column)) {
                    $before = $existing;
                    break;
                }
            }
            $row->insertBefore($cell, $before);
        }

        while (null !== $cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }

        $cell->setAttribute('t', 'inlineStr');
        $is = $this->sheet->createElementNS(self::NS, 'is');
        $t = $this->sheet->createElementNS(self::NS, 't');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($this->sheet->createTextNode($text));
        $is->appendChild($t);
        $cell->appendChild($is);
    }

    /**
     * Inserts `$count` copies of row `$after` right below it, and moves every row, merged range,
     * dimension and print area below it down accordingly.
     */
    public function insertRowsAfter(int $after, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $source = $this->row($after) ?? throw new XlsxTemplateException('portfolioTemplateWriteError');

        // Shift the rows below, from the bottom up so no two rows ever share a number.
        $rows = [];
        foreach ($this->xpath->query('//m:sheetData/m:row') ?: [] as $row) {
            if ($row instanceof \DOMElement && (int) $row->getAttribute('r') > $after) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (\DOMElement $a, \DOMElement $b): int => (int) $b->getAttribute('r') <=> (int) $a->getAttribute('r'));
        foreach ($rows as $row) {
            $this->renumber($row, (int) $row->getAttribute('r') + $count);
        }

        $anchor = $source->nextSibling;
        for ($i = 1; $i <= $count; ++$i) {
            $copy = $source->cloneNode(true);
            if (!$copy instanceof \DOMElement) {
                continue;
            }
            $this->renumber($copy, $after + $i);
            foreach ($copy->getElementsByTagNameNS(self::NS, 'c') as $cell) {
                while (null !== $cell->firstChild) {
                    $cell->removeChild($cell->firstChild);
                }
                $cell->removeAttribute('t');
            }
            $source->parentNode?->insertBefore($copy, $anchor);
        }

        foreach ($this->xpath->query('//m:mergeCells/m:mergeCell') ?: [] as $merge) {
            if ($merge instanceof \DOMElement) {
                $merge->setAttribute('ref', self::shiftRange($merge->getAttribute('ref'), $after, $count));
            }
        }

        $dimension = $this->first('//m:dimension');
        if ($dimension instanceof \DOMElement) {
            $dimension->setAttribute('ref', self::shiftRange($dimension->getAttribute('ref'), $after, $count));
        }

        foreach ($this->workbook?->getElementsByTagNameNS(self::NS, 'definedName') ?? [] as $name) {
            $name->textContent = (string) preg_replace_callback('/\$([A-Z]+)\$(\d+)/', static fn (array $m): string => '$'.$m[1].'$'.((int) $m[2] > $after ? (int) $m[2] + $count : (int) $m[2]), $name->textContent);
        }
    }

    /** The workbook with the changes, as the bytes of a new .xlsx. */
    public function save(): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'xlsx');
        if (false === $copy || !copy($this->path, $copy)) {
            throw new XlsxTemplateException('portfolioTemplateWriteError');
        }

        try {
            $zip = new \ZipArchive();
            if (true !== $zip->open($copy)) {
                throw new XlsxTemplateException('portfolioTemplateWriteError');
            }
            $zip->addFromString($this->sheetPath, (string) $this->sheet->saveXML());
            if (null !== $this->workbook) {
                $zip->addFromString('xl/workbook.xml', (string) $this->workbook->saveXML());
            }
            $zip->close();

            return (string) file_get_contents($copy);
        } finally {
            @unlink($copy);
        }
    }

    /** @return array{0: string, 1: int} */
    public static function split(string $reference): array
    {
        if (1 !== preg_match('/^([A-Z]+)(\d+)$/', $reference, $m)) {
            throw new XlsxTemplateException('portfolioTemplateWriteError');
        }

        return [$m[1], (int) $m[2]];
    }

    public static function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (\ord($letter) - 64);
        }

        return $index;
    }

    private function first(string $expression): ?\DOMElement
    {
        $nodes = $this->xpath->query($expression);
        $node = false === $nodes ? null : $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function renumber(\DOMElement $row, int $number): void
    {
        $row->setAttribute('r', (string) $number);
        foreach ($row->getElementsByTagNameNS(self::NS, 'c') as $cell) {
            [$column] = self::split($cell->getAttribute('r'));
            $cell->setAttribute('r', $column.$number);
        }
    }

    private static function shiftRange(string $range, int $after, int $count): string
    {
        return (string) preg_replace_callback('/([A-Z]+)(\d+)/', static fn (array $m): string => $m[1].((int) $m[2] > $after ? (int) $m[2] + $count : (int) $m[2]), $range);
    }

    private function readBorders(\DOMDocument $styles): void
    {
        $borders = [];
        foreach ($styles->getElementsByTagNameNS(self::NS, 'borders')->item(0)->childNodes ?? [] as $border) {
            if (!$border instanceof \DOMElement) {
                continue;
            }
            $drawn = false;
            foreach ($border->childNodes as $side) {
                if ($side instanceof \DOMElement && '' !== $side->getAttribute('style')) {
                    $drawn = true;
                }
            }
            $borders[] = $drawn;
        }

        $index = 0;
        foreach ($styles->getElementsByTagNameNS(self::NS, 'cellXfs')->item(0)->childNodes ?? [] as $xf) {
            if ($xf instanceof \DOMElement) {
                $this->bordered[$index++] = $borders[(int) $xf->getAttribute('borderId')] ?? false;
            }
        }
    }

    private function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (!\is_string($workbook) || !\is_string($rels)) {
            throw new XlsxTemplateException('portfolioTemplateNotXlsxError');
        }

        $book = self::dom($workbook);
        $sheet = $book->getElementsByTagNameNS(self::NS, 'sheet')->item(0);
        $relationId = $sheet instanceof \DOMElement ? $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id') : '';

        foreach (self::dom($rels)->getElementsByTagName('Relationship') as $relationship) {
            if ($relationship->getAttribute('Id') === $relationId) {
                $target = ltrim($relationship->getAttribute('Target'), '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        throw new XlsxTemplateException('portfolioTemplateNotXlsxError');
    }

    private static function dom(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;

        if (!@$dom->loadXML($xml, \LIBXML_NONET)) {
            throw new XlsxTemplateException('portfolioTemplateNotXlsxError');
        }

        return $dom;
    }
}
