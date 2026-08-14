<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

use App\Service\XlsxReadException;
use App\Service\XlsxSheetReader;

/**
 * Turns the school's "Liste des entreprises" export (.xlsx) into ContractRow objects.
 *
 * The export is produced by the school's own tool, so the column labels are not ours to fix: they
 * carry accents, stray dots ("Tut. ent. 2"), a trailing space ("Tut ent. 1 : Portable ") and a
 * "Stagiaire"/"Tut ent. 1" prefix that is what actually distinguishes the two e-mail columns.
 * Columns are therefore located by matching *fragments* of a folded label rather than by position
 * or by an exact string - a re-export that renames "Mail" to "E-mail", or moves a column, still
 * imports.
 *
 * Everything is read as text and only trimmed. No date is parsed here (ContractDateParser does it,
 * out of the free-text observations column) and nothing is resolved against the database
 * (ImportAnalyzer does that) - this class only answers "what does the file say".
 */
class ContractSpreadsheetReader
{
    /**
     * Canonical field => the fragments its column label must all contain, once folded.
     *
     * Ordered the way the file is, for readability only - each field takes the first column that
     * matches it, and a column is never claimed twice.
     *
     * @var array<string, list<string>>
     */
    private const array COLUMN_FRAGMENTS = [
        'classCode' => ['code classe'],
        'studentName' => ['stagiaire', 'nom'],
        'studentPhone' => ['stagiaire', 'telephone'],
        'studentEmail' => ['stagiaire', 'mail'],
        'enterpriseName' => ['entreprise', 'nom'],
        'enterpriseAddress' => ['entreprise', 'adresse'],
        // "1" is what separates the first tutor's four columns from "Tut. ent. 2 : Mail" - which
        // is empty in every export seen so far, and would otherwise claim the tutor's mail column.
        'tutorName' => ['tut', '1', 'nom'],
        'tutorPhone' => ['tut', '1', 'telephone'],
        'tutorMobile' => ['tut', '1', 'portable'],
        'tutorEmail' => ['tut', '1', 'mail'],
        'observations' => ['observation'],
    ];

    /** Without these, the line cannot describe a contract at all - see ImportAnalyzer for the rest. */
    private const array REQUIRED_COLUMNS = ['studentName', 'enterpriseName', 'tutorName', 'tutorEmail', 'observations'];

    public function __construct(private readonly XlsxSheetReader $reader)
    {
    }

    /**
     * @return list<ContractRow>
     *
     * @throws ImportFileException
     */
    public function read(string $path): array
    {
        try {
            $sheetName = $this->reader->sheetNames($path)[0] ?? throw new ImportFileException('ufaContractImportEmptyFileMessage');
            $rows = $this->reader->rows($path, $sheetName);
        } catch (XlsxReadException) {
            // The reader's own keys name the Kahoot import it was written for; the operator here
            // is looking at a different screen and a different file.
            throw new ImportFileException('ufaContractImportUnreadableFileMessage');
        }

        $header = array_shift($rows) ?? throw new ImportFileException('ufaContractImportEmptyFileMessage');
        $columns = $this->locateColumns($header);

        $contracts = [];
        foreach ($rows as $index => $row) {
            $value = static fn (string $field): string => trim($row[$columns[$field] ?? -1] ?? '');

            // A trailing blank line (Excel keeps the row when a cell was merely styled) is not an
            // empty contract - it is nothing at all.
            if ('' === $value('studentName') && '' === $value('enterpriseName') && '' === $value('tutorEmail')) {
                continue;
            }

            $contracts[] = new ContractRow(
                // +2: the header was shifted off, and worksheet lines are 1-based.
                $index + 2,
                $value('classCode'),
                $value('studentName'),
                $value('studentEmail'),
                $value('studentPhone'),
                $value('enterpriseName'),
                $value('enterpriseAddress'),
                $value('tutorName'),
                $value('tutorPhone'),
                $value('tutorMobile'),
                $value('tutorEmail'),
                $value('observations'),
            );
        }

        if ([] === $contracts) {
            throw new ImportFileException('ufaContractImportEmptyFileMessage');
        }

        return $contracts;
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int> canonical field => column index
     *
     * @throws ImportFileException
     */
    private function locateColumns(array $header): array
    {
        $folded = array_map($this->fold(...), $header);

        $columns = [];
        $claimed = [];
        foreach (self::COLUMN_FRAGMENTS as $field => $fragments) {
            foreach ($folded as $index => $label) {
                if (isset($claimed[$index]) || '' === $label) {
                    continue;
                }

                foreach ($fragments as $fragment) {
                    if (!str_contains($label, $fragment)) {
                        continue 2;
                    }
                }

                $columns[$field] = $index;
                $claimed[$index] = true;
                break;
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($columns)));
        if ([] !== $missing) {
            throw new ImportFileException('ufaContractImportMissingColumnsMessage', ['%columns%' => implode(', ', $missing)]);
        }

        return $columns;
    }

    /** Lowercased, unaccented, punctuation-free - "Tut ent. 1 : Portable " becomes "tut ent 1 portable". */
    private function fold(string $label): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        $lowered = mb_strtolower(false !== $ascii ? $ascii : $label);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $lowered) ?? '') ?? '');
    }
}
