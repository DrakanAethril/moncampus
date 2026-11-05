<?php

declare(strict_types=1);

namespace App\Service\Kahoot;

use App\Service\QuizCsvImportException;
use App\Service\XlsxReadException;
use App\Service\XlsxSheetReader;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads a Kahoot report (.xlsx) from disk into a App\Service\Kahoot\KahootReport - the one door
 * both ways in go through: the import screen (App\Service\KahootXlsxImporter) and the Claude
 * connector (App\Mcp\Tool\QuizImportKahootTool).
 */
class KahootReportReader
{
    public function __construct(
        private readonly XlsxSheetReader $reader,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws QuizCsvImportException when the file is not a readable Kahoot report
     */
    public function read(string $path, string $fileName): KahootReport
    {
        try {
            $sheets = [];
            foreach ($this->reader->sheetNames($path) as $name) {
                $sheets[$name] = $this->reader->rows($path, $name);
            }
        } catch (XlsxReadException $exception) {
            throw new QuizCsvImportException($exception->getMessageKey(), $exception->getParameters());
        }

        $report = KahootReportParser::parse($sheets, $fileName);

        // Said once, in the quiz itself: where it came from, and what a report never carries.
        $document = $report->document;
        $document['template'] = [
            'name' => $report->title,
            'description' => null !== $report->playedOn
                ? $this->translator->trans('kahootImportDescriptionPlayedTemplate', ['%date%' => $report->playedOn])
                : $this->translator->trans('kahootImportDescription'),
        ];

        return new KahootReport($report->title, $report->playedOn, $document, $report->notes, $report->typeCounts);
    }

    /** One note, as a sentence. */
    public function describe(KahootNote $note): string
    {
        return $this->translator->trans('kahootImportNote_'.$note->kind, [
            '%number%' => $note->questionNumber,
            '%type%' => $note->parameters['type'] ?? '',
            '%tolerance%' => $note->parameters['tolerance'] ?? '',
        ]);
    }
}
