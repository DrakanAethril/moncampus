<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A CSV read as a grid of strings, recovering from the three things a spreadsheet export routinely
 * gets wrong: a UTF-8 BOM, a Windows-1252 encoding (the accents would otherwise land in the
 * database as mojibake, and MySQL would reject them outright) and a delimiter that is not the one
 * the reader expected.
 *
 * Extracted from App\Service\QuizCsvImporter::readRows(), which was the only reading of the kind
 * until the class import needed the same four fixes - shared rather than copied so a file that one
 * screen accepts is never refused by the other.
 *
 * Says nothing about what the columns mean: mapping them is each importer's own business.
 */
final class CsvTable
{
    private const array DELIMITERS = [';', ',', "\t", '|'];

    /** @param list<list<string>> $rows */
    private function __construct(private readonly array $rows)
    {
    }

    /** @throws \RuntimeException when the content cannot be streamed at all */
    public static function fromContent(string $content): self
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if ('' === trim($content)) {
            return new self([]);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to open a temporary stream to read the CSV.');
        }

        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        $delimiter = self::detectDelimiter($content);
        // Escape character explicitly disabled: a backslash inside a cell (a Windows path, a
        // regex) is content, not an escape, and only doubled quotes delimit-escape a quote here.
        while (false !== ($row = fgetcsv($stream, 0, $delimiter, '"', ''))) {
            $rows[] = array_map(static fn (mixed $cell): string => (string) $cell, $row);
        }
        fclose($stream);

        return new self($rows);
    }

    /**
     * Every row the file carries, header included - cells are strings, never null, so callers
     * never have to guard the blank line fgetcsv() hands back as [null].
     *
     * @return list<list<string>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The spelling two headers must share to be the same header: lowercase, unaccented, every run
     * of punctuation or space reduced to a single underscore. `Prénom`, `PRENOM` and `prenom ` all
     * fold to `prenom`.
     */
    public static function fold(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', AsciiFolder::fold($value)), '_');
    }

    private static function detectDelimiter(string $content): string
    {
        $firstLine = preg_split('/\r\n|\r|\n/', $content, 2)[0] ?? '';
        $best = ';';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($firstLine, $delimiter);
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
