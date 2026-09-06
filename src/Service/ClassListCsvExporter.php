<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\User;

/**
 * The two CSV files the « Exporter » button of a class list hands back.
 *
 * The student one is deliberately **the import's own format** (see
 * App\Controller\DirectoryClassImportController::template() and App\Service\ClassImport\
 * ClassImportCsvReader): `nom;prenom;mail` then the free columns `option` and `modalite`. Exporting
 * a class and re-importing it must be a round trip - that is what makes this file usable to move a
 * class from one year to the next, and why nothing is prettified here.
 *
 * The `mail` column is the **contact address** (User::getContactEmail()), on both sides: the import
 * matches an existing account on it and fills it in (ClassImportContextFactory, ClassImportExecutor
 * ::fillContactEmail()), and User::$email is the directory's internal address nobody necessarily
 * reads. Writing that one here produced a file whose addresses matched no account on the way back
 * in. Whenever a screen or a file says « adresse mail » on this platform, it is the contact one.
 *
 * A student holding two options gets **two `option` columns**, not one cell with a separator: the
 * reader matches a whole cell against an option's name or short name, so « SLAM + SISR » would come
 * back as an unknown value and block the whole file. Duplicate headers cost nothing - free columns
 * are read by position.
 *
 * The teacher one is the three columns and nothing else, per the same button's second use: there
 * is no import to round-trip to, so there is nothing else to carry.
 */
class ClassListCsvExporter
{
    public function __construct(
        // How a person is spelled across the two columns is the roster's rule, not this class's -
        // the printed sheet and the file must never disagree about who somebody is.
        private readonly ClassRoster $roster,
    ) {
    }

    /**
     * A BOM, then CRLF: the file is opened by double-click in Excel far more often than read by a
     * program, and without them the accents are mojibake and the last line is missing. CsvTable
     * strips the BOM again on the way back in.
     */
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param list<User>                $students
     * @param array<int, list<Option>>  $optionsByStudentId
     * @param array<int, list<Modality>> $modalitiesByStudentId
     */
    public function students(array $students, array $optionsByStudentId, array $modalitiesByStudentId): string
    {
        // At least one of each, so an empty class still produces the file the import screen
        // describes rather than a three-column stump nobody recognises.
        $optionColumns = max(1, $this->widest($students, $optionsByStudentId));
        $modalityColumns = max(1, $this->widest($students, $modalitiesByStudentId));

        $rows = [array_merge(
            ['nom', 'prenom', 'mail'],
            array_fill(0, $optionColumns, 'option'),
            array_fill(0, $modalityColumns, 'modalite'),
        )];

        foreach ($students as $student) {
            $id = $student->getId();
            $options = $this->labels($optionsByStudentId[$id] ?? []);
            $modalities = $this->labels($modalitiesByStudentId[$id] ?? []);

            $rows[] = array_merge(
                [$this->roster->surname($student), $this->roster->given($student), $student->getContactEmail() ?? ''],
                array_pad($options, $optionColumns, ''),
                array_pad($modalities, $modalityColumns, ''),
            );
        }

        return $this->render($rows);
    }

    /** @param list<User> $teachers */
    public function teachers(array $teachers): string
    {
        $rows = [['nom', 'prenom', 'mail']];

        foreach ($teachers as $teacher) {
            $rows[] = [$this->roster->surname($teacher), $this->roster->given($teacher), $teacher->getContactEmail() ?? ''];
        }

        return $this->render($rows);
    }

    /** @param list<list<string>> $rows */
    private function render(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to open a temporary stream to write the CSV.');
        }

        foreach ($rows as $row) {
            // Escape character explicitly disabled, symmetrically with App\Service\CsvTable: a
            // backslash in a name is content, not an escape.
            fputcsv($stream, $row, ';', '"', '', "\r\n");
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return self::BOM.$csv;
    }

    /**
     * @param list<Option|Modality> $values
     *
     * @return list<string>
     */
    private function labels(array $values): array
    {
        $labels = [];
        foreach ($values as $value) {
            $short = trim($value->getShortName() ?? '');
            $labels[] = '' !== $short ? $short : $value->getName();
        }

        return $labels;
    }

    /**
     * @param list<User>                        $users
     * @param array<int, list<Option|Modality>> $valuesByUserId
     */
    private function widest(array $users, array $valuesByUserId): int
    {
        $widest = 0;
        foreach ($users as $user) {
            $widest = max($widest, \count($valuesByUserId[$user->getId()] ?? []));
        }

        return $widest;
    }
}
