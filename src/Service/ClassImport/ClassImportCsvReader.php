<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Service\CsvTable;

/**
 * Turns the CSV a secretariat exports into StudentRow objects: three named columns, and as many
 * free columns as the file carries.
 *
 * Answers only "what does the file say" - nothing is resolved against the database and no name is
 * normalised here. What it does decide is what cannot be read at all, and those three refusals
 * throw rather than being reported per line: a file without a `mail` column, an empty file, and a
 * file of more than 300 students, which is no longer a class.
 */
final class ClassImportCsvReader
{
    // Past this, the file is something else - a full export, another school's list. Refused whole
    // rather than truncated: an import nobody can see the end of is worse than no import.
    public const int MAX_ROWS = 300;

    /** Folded header spelling => the column it names. First spelling seen wins. */
    private const array COLUMN_ALIASES = [
        'nom' => 'lastname',
        'nom_de_famille' => 'lastname',
        'prenom' => 'firstname',
        'mail' => 'email',
        'email' => 'email',
        'e_mail' => 'email',
        'adresse_mail' => 'email',
        'courriel' => 'email',
    ];

    private const array REQUIRED_COLUMNS = ['lastname' => 'nom', 'firstname' => 'prenom', 'email' => 'mail'];

    /**
     * @return list<StudentRow>
     *
     * @throws ClassImportFileException
     */
    public function read(string $content): array
    {
        $rows = CsvTable::fromContent($content)->rows();
        $header = array_shift($rows);

        if (null === $header || [] === $rows) {
            throw new ClassImportFileException('classImportFileEmptyMessage');
        }

        $columns = $this->mapColumns($header);
        foreach (self::REQUIRED_COLUMNS as $key => $label) {
            if (!isset($columns[$key])) {
                throw new ClassImportFileException('classImportFileMissingColumnMessage', ['%column%' => $label]);
            }
        }

        $freeColumns = $this->mapFreeColumns($header, $columns, $rows);

        $students = [];
        foreach ($rows as $index => $row) {
            $lastname = $this->cell($row, $columns['lastname']);
            $firstname = $this->cell($row, $columns['firstname']);

            // A spreadsheet keeps rows that only carry formatting. They are noise, not an error.
            if ('' === $lastname && '' === $firstname) {
                continue;
            }

            $freeCells = [];
            foreach ($freeColumns as $position => $label) {
                $freeCells[] = new FreeCell($label, CsvTable::fold($label), $this->cell($row, $position));
            }

            $students[] = new StudentRow(
                $index + 2,
                $lastname,
                $firstname,
                $this->cell($row, $columns['email']),
                $freeCells,
            );
        }

        if ([] === $students) {
            throw new ClassImportFileException('classImportFileEmptyMessage');
        }

        if (\count($students) > self::MAX_ROWS) {
            throw new ClassImportFileException('classImportFileTooManyRowsMessage', ['%max%' => self::MAX_ROWS]);
        }

        return $students;
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int> column key => position in a row
     */
    private function mapColumns(array $header): array
    {
        $columns = [];
        foreach ($header as $position => $label) {
            $key = self::COLUMN_ALIASES[CsvTable::fold($label)] ?? null;
            if (null !== $key && !isset($columns[$key])) {
                $columns[$key] = $position;
            }
        }

        return $columns;
    }

    /**
     * Every other column, except the ones an export drags along empty from header to last row - a
     * spreadsheet routinely writes those out to BZ, and each would otherwise be a free cell the
     * analysis has to say something about.
     *
     * @param list<string>        $header
     * @param array<string, int>  $columns
     * @param list<list<string>>  $rows
     *
     * @return array<int, string> position in a row => the header as the file writes it
     */
    private function mapFreeColumns(array $header, array $columns, array $rows): array
    {
        $taken = array_values($columns);
        $free = [];

        foreach ($header as $position => $label) {
            if (\in_array($position, $taken, true)) {
                continue;
            }

            if ('' === trim($label) && !$this->columnHasAnyValue($rows, $position)) {
                continue;
            }

            $free[$position] = trim($label);
        }

        return $free;
    }

    /** @param list<list<string>> $rows */
    private function columnHasAnyValue(array $rows, int $position): bool
    {
        foreach ($rows as $row) {
            if ('' !== trim($row[$position] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $row */
    private function cell(array $row, int $position): string
    {
        return trim($row[$position] ?? '');
    }
}
