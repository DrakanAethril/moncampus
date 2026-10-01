<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool\Import;

use App\Service\XlsxReadException;
use App\Service\XlsxSheetReader;

use function Symfony\Component\String\u;

/**
 * Reads the file of « Importer l'historique » - the model's .xlsx, or a CSV saved from it (`;` or
 * `,`, whichever the header line uses). Headers are matched without case, accents or punctuation
 * (« Code postal » is `code_postal`); a column the model does not have is ignored, a missing
 * required one refuses the file.
 */
class HostingImportReader
{
    private const array REQUIRED = ['type', 'annee', 'filiere', 'entreprise'];

    public function __construct(
        private readonly XlsxSheetReader $xlsx,
    ) {
    }

    /**
     * @return list<HostingImportRow>
     *
     * @throws HostingImportFileException
     */
    public function read(string $path, string $originalName): array
    {
        $rows = str_ends_with(mb_strtolower($originalName), '.csv') ? $this->csv($path) : $this->spreadsheet($path);
        $header = array_shift($rows) ?? throw new HostingImportFileException('enterpriseImportEmptyFileError');

        $columns = [];
        foreach ($header as $index => $label) {
            $key = str_replace(' ', '_', u($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString());
            if (\in_array($key, HostingImportRow::COLUMNS, true) && !isset($columns[$key])) {
                $columns[$key] = $index;
            }
        }

        $missing = array_diff(self::REQUIRED, array_keys($columns));
        if ([] !== $missing) {
            throw new HostingImportFileException('enterpriseImportMissingColumnsError', ['%columns%' => implode(', ', $missing)]);
        }

        $result = [];
        foreach ($rows as $index => $row) {
            $values = [];
            foreach ($columns as $key => $column) {
                $values[$key] = trim($row[$column] ?? '');
            }
            if ('' === implode('', $values)) {
                continue;
            }
            // +2: the header was shifted off, and lines are 1-based.
            $result[] = new HostingImportRow($index + 2, $values);
        }

        if ([] === $result) {
            throw new HostingImportFileException('enterpriseImportEmptyFileError');
        }
        if (\count($result) > 2000) {
            throw new HostingImportFileException('enterpriseImportTooLongError');
        }

        return $result;
    }

    /** @return list<list<string>> */
    private function spreadsheet(string $path): array
    {
        try {
            $sheet = $this->xlsx->sheetNames($path)[0] ?? throw new HostingImportFileException('enterpriseImportEmptyFileError');

            return $this->xlsx->rows($path, $sheet);
        } catch (XlsxReadException) {
            throw new HostingImportFileException('enterpriseImportUnreadableFileError');
        }
    }

    /** @return list<list<string>> */
    private function csv(string $path): array
    {
        $content = file_get_contents($path);
        if (false === $content || '' === $content) {
            throw new HostingImportFileException('enterpriseImportEmptyFileError');
        }
        // A CSV saved by Excel in France is Windows-1252, without a BOM.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://memory', 'r+');
        if (false === $handle) {
            throw new HostingImportFileException('enterpriseImportUnreadableFileError');
        }
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (false !== ($row = fgetcsv($handle, null, $delimiter, '"', ''))) {
            $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }
}
