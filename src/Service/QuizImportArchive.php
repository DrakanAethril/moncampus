<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The JSON documents held in an uploaded `.zip`, in the order a teacher would read their file names.
 *
 * The other way to hand over a batch. A model that produced ten quizzes rarely gives them back in
 * one message, and the teacher who saved them one file at a time has ten files, not one paste - so
 * the archive is the same batch by another door, and comes out of this class in exactly the shape
 * App\Service\QuizImportBatchReader already reads.
 *
 * Nothing is extracted: entries are read into memory by name and never written anywhere, which is
 * what makes the zip-slip and symlink rules of App\Service\WikiArchiveSafety beside the point here
 * (that importer writes files to disk; this one does not). What does still apply is the zip bomb,
 * and the three caps below are its answer - a quiz document is a few dozen kilobytes.
 *
 * Everything that is not a `.json` is ignored rather than refused. An archive built by a teacher
 * carries the operating system's own litter (`__MACOSX/`, `.DS_Store`), and a batch that refused to
 * open because of a file the teacher never created would be inexplicable from the outside.
 */
final class QuizImportArchive
{
    /** Well past the batch ceiling, so an archive that is too big is named as such rather than as a bad zip. */
    public const int MAX_ENTRIES = 200;

    public const int MAX_ENTRY_BYTES = 2 * 1024 * 1024;

    public const int MAX_TOTAL_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly JsonDocumentSplitter $splitter)
    {
    }

    /**
     * @return list<array{json: string, fileName: string}>
     *
     * @throws QuizCsvImportException
     */
    public function documents(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new QuizCsvImportException('quizBatchNotAnArchiveError');
        }

        try {
            return $this->readEntries($zip, $this->jsonEntries($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * The `.json` entries, checked and sorted. Natural order rather than plain string order:
     * `quiz-2.json` is the second quiz, and `quiz-10.json` sorting before it would reorder a batch
     * the teacher named carefully.
     *
     * @return list<string>
     *
     * @throws QuizCsvImportException
     */
    private function jsonEntries(\ZipArchive $zip): array
    {
        $names = [];
        $total = 0;

        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new QuizCsvImportException('quizBatchArchiveTooManyEntriesError', ['%max%' => self::MAX_ENTRIES]);
        }

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if (false === $stat) {
                continue;
            }

            /** @var array{name: string, size: int} $stat */
            $name = $stat['name'];
            if (str_ends_with($name, '/') || !$this->isJsonDocument($name)) {
                continue;
            }

            $size = (int) $stat['size'];
            if ($size > self::MAX_ENTRY_BYTES) {
                throw new QuizCsvImportException('quizBatchArchiveEntryTooLargeError', ['%file%' => basename($name)]);
            }

            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new QuizCsvImportException('quizBatchArchiveTooLargeError');
            }

            $names[] = $name;
        }

        if ([] === $names) {
            throw new QuizCsvImportException('quizBatchArchiveNoJsonError');
        }

        usort($names, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $names;
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{json: string, fileName: string}>
     */
    private function readEntries(\ZipArchive $zip, array $names): array
    {
        $documents = [];

        foreach ($names as $name) {
            $contents = $zip->getFromName($name);
            if (false === $contents) {
                continue;
            }

            // An entry is normally one document, but a teacher who saved a whole answer into a
            // single file has several in it - the same shape the paste box takes, read the same way
            // rather than refused as invalid JSON.
            foreach ($this->splitter->split($contents) as $json) {
                $documents[] = ['json' => $json, 'fileName' => basename($name)];
            }
        }

        return $documents;
    }

    /** A `.json` the teacher put there, as opposed to the operating system's own litter. */
    private function isJsonDocument(string $name): bool
    {
        $base = basename($name);

        return !str_starts_with($base, '.')
            && !str_starts_with($name, '__MACOSX/')
            && 'json' === strtolower(pathinfo($base, \PATHINFO_EXTENSION));
    }
}
