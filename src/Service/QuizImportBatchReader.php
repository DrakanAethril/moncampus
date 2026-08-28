<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A batch of pasted or archived documents, read into one payload per quiz.
 *
 * The single-document tunnel (App\Controller\QuizImportController::preview()) was written when an
 * import meant one conversation with a model and one quiz out of it. Asking for a term's worth of
 * quizzes in one go is the ordinary case now, and doing it one document at a time means walking the
 * four steps of the assistant once per quiz - the prompt is the same, the séance is the same, only
 * the paste changes.
 *
 * Two things this owns, and they are the whole of it:
 *
 * - **Every document keeps its own reader.** A batch is not required to be homogeneous: the
 *   registry dispatches on the format each document names, so a `moncampus-quiz/1` and an older
 *   `moncampus-zones/1` can travel in the same paste.
 * - **A document nobody can read refuses the batch, by rank and by name.** Per-question problems
 *   keep the behaviour they have always had - listed on the verification screen, skipped - but a
 *   whole quiz that silently vanishes from a paste of five is a loss with no message attached to
 *   it. Same answer, same reason, as the alternance contract import gives a blocking row.
 *
 * @phpstan-type BatchDocument array{json: string, fileName: string}
 * @phpstan-type BatchPayload array{format: string, name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>}
 */
final class QuizImportBatchReader
{
    /**
     * What one paste or one archive may carry. Well past what a teacher produces in a sitting, and
     * low enough that the verification screen - which renders every question of every quiz - stays
     * a page rather than a download.
     */
    public const int MAX_DOCUMENTS = 25;

    public function __construct(
        private readonly InteractiveQuizImporterRegistry $registry,
        private readonly MixedJsonImporter $mixed,
        private readonly JsonDocumentSplitter $splitter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * A paste box, cut into its documents and read. One entry back means the teacher pasted one
     * quiz, which is what lets the paste step keep sending it down the screen it has always used.
     *
     * @return list<BatchPayload>
     *
     * @throws QuizCsvImportException
     */
    public function readPaste(string $text, string $fileName): array
    {
        return $this->read(array_map(
            static fn (string $json): array => ['json' => $json, 'fileName' => $fileName],
            $this->splitter->split($text),
        ));
    }

    /**
     * @param list<BatchDocument> $documents
     *
     * @return list<BatchPayload>
     *
     * @throws QuizCsvImportException
     */
    public function read(array $documents): array
    {
        if ([] === $documents) {
            throw new QuizCsvImportException('quizBatchNoDocumentError');
        }

        if (\count($documents) > self::MAX_DOCUMENTS) {
            throw new QuizCsvImportException('quizBatchTooManyDocumentsError', ['%max%' => self::MAX_DOCUMENTS]);
        }

        $payloads = [];
        foreach ($documents as $index => $document) {
            try {
                $payloads[] = $this->registry
                    ->forDocument($document['json'], $this->mixed->family())
                    ->parse($document['json'], $document['fileName'], 1);
            } catch (QuizCsvImportException $exception) {
                // The reader's own message says what is wrong with the document; this says which
                // one of the batch it is talking about, which a rank alone would not. The reason is
                // translated here rather than passed on as a key: it travels as a *parameter* of
                // the outer message, and nothing downstream would translate it a second time.
                throw new QuizCsvImportException('quizBatchDocumentRefusedError', ['%number%' => $index + 1, '%file%' => $document['fileName'], '%reason%' => $this->translator->trans($exception->getMessageKey(), $exception->getParameters())]);
            }
        }

        return $payloads;
    }
}
