<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\QuestionType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-quiz/1" format: one document for the twelve types, each question naming its own.
 *
 * This is what makes the import screen's prompt a *constructor* rather than four fixed prompts - the
 * teacher ticks the types worth using and asks one model for one file, in which each notion gets the
 * form it deserves. Reading four separate formats stays possible (the application emitted them, so
 * teachers hold such files); only writing is replaced.
 *
 * It reads nothing itself. Each question is re-emitted as a one-question document of its own family
 * and handed to that family's reader, which is why adding a type to a family costs nothing here.
 * Two things it does own:
 *
 * - **The document's order.** Grouping the questions by family - the obvious way to delegate whole
 *   sub-documents - would quietly undo the "vary the types, never two of a kind in a row" the prompt
 *   asks for. Questions are therefore delegated one by one, in order, both on the way in and on the
 *   way into the bank.
 * - **The numbering of errors.** A rejected question is named by its place in the file the teacher
 *   is looking at, which is what `$firstNumber` on InteractiveQuizImporter::parse() exists for.
 *
 * The readers are injected one by one rather than through InteractiveQuizImporterRegistry, which
 * collects every importer - including this one, whose construction would then require itself.
 */
final class MixedJsonImporter implements InteractiveQuizImporter
{
    public const string FORMAT = 'moncampus-quiz/1';

    // Same ceiling and same reasoning as QuizCsvImporter::MAX_QUESTIONS.
    public const int MAX_QUESTIONS = 500;

    /** @var list<InteractiveQuizImporter> */
    private readonly array $readers;

    public function __construct(
        private readonly TranslatorInterface $translator,
        SimpleJsonImporter $simple,
        ZoneJsonImporter $zones,
        MatchingJsonImporter $matching,
        NumericJsonImporter $numeric,
        ShortAnswerJsonImporter $shortAnswer,
    ) {
        $this->readers = [$simple, $zones, $matching, $numeric, $shortAnswer];
    }

    public function family(): string
    {
        return 'quiz';
    }

    public function formatTag(): string
    {
        return self::FORMAT;
    }

    public function payloadFormat(): string
    {
        return 'mixed';
    }

    public function handles(QuestionType $type): bool
    {
        return null !== $this->readerFor($type);
    }

    public function exampleLabels(): array
    {
        return MixedExampleCatalog::labels();
    }

    public function exampleJson(string $key): ?string
    {
        return MixedExampleCatalog::json($key);
    }

    /**
     * @return array{format: 'mixed', name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>}
     *
     * @throws QuizCsvImportException when the document as a whole is unusable
     */
    public function parse(string $json, string $fileName = 'import.json', int $firstNumber = 1): array
    {
        try {
            $document = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QuizCsvImportException('mixedImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new QuizCsvImportException('mixedImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new QuizCsvImportException('mixedImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $rawQuestions = \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        if ([] === $rawQuestions) {
            throw new QuizCsvImportException('mixedImportNoQuestionError');
        }
        if (\count($rawQuestions) > self::MAX_QUESTIONS) {
            throw new QuizCsvImportException('mixedImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
        }

        $template = \is_array($document['template'] ?? null) ? $document['template'] : [];

        $questions = [];
        $errors = [];
        foreach ($rawQuestions as $index => $raw) {
            $number = $index + $firstNumber;
            $reader = \is_array($raw) ? $this->readerFor($this->typeOf($raw)) : null;
            if (null === $reader) {
                $errors[] = $this->translator->trans('mixedImportQuestionUnknownTypeError', ['%number%' => $number]);
                continue;
            }

            try {
                // One question at a time, as a document of that family's own format: the readers
                // validate a whole document, and this keeps both the order and the numbering right.
                $sub = $reader->parse((string) json_encode([
                    'format' => $reader->formatTag(),
                    'questions' => [$raw],
                ], \JSON_UNESCAPED_UNICODE), $fileName, $number);
            } catch (QuizCsvImportException) {
                // A single question cannot make a family's document unusable in any way we could
                // describe better than "this reader refused it".
                $errors[] = $this->translator->trans('mixedImportQuestionRefusedError', ['%number%' => $number]);
                continue;
            }

            foreach ($sub['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($sub['questions'] as $question) {
                $questions[] = $question;
            }
        }

        return [
            'format' => 'mixed',
            'name' => $this->stringOf($template['name'] ?? null) ?? $this->translator->trans('mixedImportDefaultTemplateName'),
            'subject' => $this->stringOf($template['subject'] ?? null),
            'description' => $this->stringOf($template['description'] ?? null),
            'fileName' => $fileName,
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<array-key, mixed> $questions
     */
    public function appendQuestions(QuizTemplate $template, array $questions, bool $copyImages = true): void
    {
        foreach (array_values($questions) as $raw) {
            if (!\is_array($raw)) {
                continue;
            }

            // Again one at a time: every reader appends at the end of whatever bank it finds, so
            // this is what interleaves the families back into the document's order.
            $this->readerFor($this->typeOf($raw))?->appendQuestions($template, [$raw], $copyImages);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function exportQuestion(QuizQuestion $question): ?array
    {
        return $this->readerFor($question->getType())?->exportQuestion($question);
    }

    /**
     * A whole quiz as one document, whatever its questions are made of - which four per-family
     * downloads could not produce for a heterogeneous bank.
     *
     * @return array<string, mixed>
     */
    public function export(QuizTemplate $template): array
    {
        $questions = [];
        foreach ($template->getQuestions() as $question) {
            $exported = $this->exportQuestion($question);
            if (null !== $exported) {
                $questions[] = $exported;
            }
        }

        return [
            'format' => self::FORMAT,
            'template' => [
                'name' => $template->getName(),
                'subject' => $template->getSubject(),
                'description' => $template->getDescription(),
            ],
            'questions' => $questions,
        ];
    }

    /** @param array<array-key, mixed> $raw */
    private function typeOf(array $raw): ?QuestionType
    {
        $type = $raw['type'] ?? null;

        return QuestionType::tryFrom(\is_scalar($type) ? trim((string) $type) : '');
    }

    private function readerFor(?QuestionType $type): ?InteractiveQuizImporter
    {
        if (null === $type) {
            return null;
        }

        foreach ($this->readers as $reader) {
            if ($reader->handles($type)) {
                return $reader;
            }
        }

        return null;
    }

    private function stringOf(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}
