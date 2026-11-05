<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Kahoot\KahootNote;
use App\Service\Kahoot\KahootReportReader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The import screen's way in for a Kahoot game report (.xlsx): the report is read into a
 * « moncampus-quiz/1 » document (App\Service\Kahoot\KahootReportReader, shared with the Claude
 * connector) and that document goes through the mixed reader like a pasted one - same validation,
 * same preview, same creation step, every question type a Kahoot carries.
 *
 * What the reading has to say is split in two on the preview: a question left out is listed with
 * the rows the reader refused, a guess (a slider's margin) is a note above the questions.
 */
class KahootXlsxImporter
{
    public function __construct(
        private readonly KahootReportReader $reader,
        private readonly MixedJsonImporter $importer,
    ) {
    }

    /**
     * @return array{format: 'mixed', name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>, notes: list<string>}
     *
     * @throws QuizCsvImportException
     */
    public function parse(UploadedFile $file): array
    {
        $report = $this->reader->read($file->getPathname(), $file->getClientOriginalName());
        if ([] === ($report->document['questions'] ?? [])) {
            throw new QuizCsvImportException('kahootImportNoQuestionError');
        }

        $payload = $this->importer->parse((string) json_encode($report->document, \JSON_UNESCAPED_UNICODE), $file->getClientOriginalName());

        $dropped = array_values(array_filter($report->notes, static fn (KahootNote $note): bool => $note->dropsQuestion()));
        $guesses = array_values(array_filter($report->notes, static fn (KahootNote $note): bool => !$note->dropsQuestion()));

        $payload['errors'] = [...$payload['errors'], ...array_map($this->reader->describe(...), $dropped)];

        return $payload + ['notes' => array_map($this->reader->describe(...), $guesses)];
    }
}
