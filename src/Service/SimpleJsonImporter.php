<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\BlankMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Util\BlankTextParser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-simple/1" format: the six types that had no JSON reader - QCM, QCM multiple,
 * vrai/faux, image, remise en ordre and texte à trous.
 *
 * They are the types whose answers are rows rather than a configuration JSON, plus the one
 * config-driven type nobody had written a document format for. Until now they arrived by CSV only,
 * which is why the mixed format could not cover the twelve types (see
 * design/comparaison/conception_import_quiz_ia.md, obstacle 2).
 *
 * Their answer semantics are the CSV's, and not by imitation: parse() produces the very payload
 * shape QuizCsvImporter emits and hands it to QuizCsvImporter::appendQuestions(). "correct" is
 * "bonnes" - 1-based indices, the full sequence for a remise en ordre - and there is exactly one
 * place where that is turned into entities. Two readers of the same types drifting apart is the
 * failure this avoids.
 *
 * Its own addition is `media`: the reference (or the name) of a picture the question is about. See
 * App\Service\QuizImportImageBatch for why a short key and not an URL.
 *
 * @phpstan-import-type QuizImportQuestion from QuizCsvImporter
 *
 * @phpstan-type SimpleImportQuestion array{
 *     type: string,
 *     difficulty: ?string,
 *     label: string,
 *     explanation: ?string,
 *     points: float,
 *     blankMode: ?string,
 *     timeMode: string,
 *     timeSeconds: ?int,
 *     timecode: ?int,
 *     answers: list<array{label: string, correct: bool}>,
 *     blanks: list<list<string>>,
 *     mediaRef: ?string,
 *     mediaName: ?string,
 * }
 */
final class SimpleJsonImporter implements InteractiveQuizImporter
{
    public const string FORMAT = 'moncampus-simple/1';

    // Same ceiling and same reasoning as QuizCsvImporter::MAX_QUESTIONS.
    public const int MAX_QUESTIONS = 500;

    private const array TYPES = [
        QuestionType::Qcm,
        QuestionType::QcmMulti,
        QuestionType::VraiFaux,
        QuestionType::Image,
        QuestionType::Ordre,
        QuestionType::TexteATrous,
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly QuizCsvImporter $csvImporter,
        private readonly QuizImportImages $images,
    ) {
    }

    public function family(): string
    {
        return 'simple';
    }

    public function formatTag(): string
    {
        return self::FORMAT;
    }

    public function payloadFormat(): string
    {
        return 'simple';
    }

    public function handles(QuestionType $type): bool
    {
        return \in_array($type, self::TYPES, true);
    }

    public function exampleLabels(): array
    {
        return [];
    }

    public function exampleJson(string $key): ?string
    {
        return null;
    }

    /**
     * @return array{format: 'simple', name: string, subject: ?string, description: ?string, fileName: string, questions: list<SimpleImportQuestion>, errors: list<string>}
     *
     * @throws QuizCsvImportException when the document as a whole is unusable
     */
    public function parse(string $json, string $fileName = 'import.json', int $firstNumber = 1): array
    {
        try {
            $document = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QuizCsvImportException('simpleImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new QuizCsvImportException('simpleImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new QuizCsvImportException('simpleImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $rawQuestions = \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        if ([] === $rawQuestions) {
            throw new QuizCsvImportException('simpleImportNoQuestionError');
        }
        if (\count($rawQuestions) > self::MAX_QUESTIONS) {
            throw new QuizCsvImportException('simpleImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
        }

        $template = \is_array($document['template'] ?? null) ? $document['template'] : [];

        $questions = [];
        $errors = [];
        foreach ($rawQuestions as $index => $raw) {
            try {
                $questions[] = $this->parseQuestion(\is_array($raw) ? $raw : []);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $this->translator->trans($exception->getMessage(), ['%number%' => $index + $firstNumber]);
            }
        }

        return [
            'format' => 'simple',
            'name' => $this->stringOf($template['name'] ?? null) ?? $this->translator->trans('simpleImportDefaultTemplateName'),
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

            // One at a time, so the question the media belongs to is the last one added - and so a
            // mixed document keeps its order (App\Service\MixedJsonImporter).
            /** @var QuizImportQuestion $row */
            $row = $raw;
            $this->csvImporter->appendQuestions($template, [$row]);

            $question = $template->getQuestions()->last();
            if ($question instanceof QuizQuestion) {
                $this->bindMedia($question, $raw, $copyImages);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function bindMedia(QuizQuestion $question, array $raw, bool $copyImages): void
    {
        $ref = $this->stringOf($raw['mediaRef'] ?? null);
        $key = null !== $ref ? $this->images->keyForQuestion($ref, $copyImages) : null;
        if (null !== $key) {
            $question->attachMedia($key);

            return;
        }

        // Nothing to resolve: the question keeps the name it was given, and reads as incomplete
        // rather than as an error (App\Service\QuizQuestionCompleteness).
        $question->setExpectedMediaName($this->stringOf($raw['mediaName'] ?? null) ?? $ref);
    }

    /**
     * @return array<string, mixed>|null null when this reader does not own the question's type
     */
    public function exportQuestion(QuizQuestion $question): ?array
    {
        if (!$this->handles($question->getType())) {
            return null;
        }

        $item = [
            'type' => $question->getType()->value,
            'label' => (string) $question->getLabel(),
            'difficulty' => $question->getDifficulty()?->value,
        ];

        if (QuestionType::TexteATrous === $question->getType()) {
            $item['blanks'] = $question->getBlankAnswers();
            $item['mode'] = $question->getBlankMode()->value;
            $item['points'] = $question->getPoints();
        } else {
            $labels = [];
            $correct = [];
            foreach ($question->getAnswers() as $position => $answer) {
                $labels[] = (string) $answer->getLabel();
                // A remise en ordre is stored *in* its answer sequence, so the exported sequence is
                // the identity - the shuffle the document arrived with is not part of the question.
                if (QuestionType::Ordre === $question->getType() || $answer->isCorrect()) {
                    $correct[] = $position + 1;
                }
            }
            $item['answers'] = $labels;
            $item['correct'] = $correct;
        }

        if (null !== $question->getExplanation()) {
            $item['explanation'] = $question->getExplanation();
        }

        return $item;
    }

    /**
     * The reverse direction - this reader's types back out as a "moncampus-simple/1" document.
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

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return SimpleImportQuestion
     *
     * @throws \InvalidArgumentException carrying the error's translation key
     */
    private function parseQuestion(array $raw): array
    {
        $type = QuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '');
        if (null === $type || !$this->handles($type)) {
            throw new \InvalidArgumentException('simpleImportQuestionBadTypeError');
        }

        $label = $this->stringOf($raw['label'] ?? null);
        if (null === $label) {
            throw new \InvalidArgumentException('simpleImportQuestionNoLabelError');
        }

        $points = is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0;
        $media = \is_array($raw['media'] ?? null) ? $raw['media'] : [];
        $ref = $this->stringOf($media['ref'] ?? null);

        $question = [
            'type' => $type->value,
            'difficulty' => QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? '')?->value,
            'label' => $label,
            'explanation' => $this->stringOf($raw['explanation'] ?? null),
            'points' => max(0.25, $points),
            'blankMode' => null,
            'timeMode' => 'quiz',
            'timeSeconds' => null,
            'timecode' => null,
            'answers' => [],
            'blanks' => [],
            'mediaRef' => $ref,
            // An address is treated exactly like a file name: something to attach, never something
            // to go and fetch from an import screen (conception, section 5 bis).
            'mediaName' => $this->stringOf($media['name'] ?? null) ?? $this->stringOf($media['url'] ?? null) ?? $ref,
        ];

        return QuestionType::TexteATrous === $type
            ? $this->parseBlanks($question, $raw)
            : $this->parseAnswers($question, $raw, $type);
    }

    /**
     * @param SimpleImportQuestion    $question
     * @param array<array-key, mixed> $raw
     *
     * @return SimpleImportQuestion
     */
    private function parseAnswers(array $question, array $raw, QuestionType $type): array
    {
        $options = $this->stringListOf($raw['answers'] ?? null);
        $correct = $this->correctIndexesOf($raw['correct'] ?? null, $type);

        // Vrai/faux writes its own two options: asking a model for them invites "Oui"/"Non", and
        // the type's whole point is that the two are fixed.
        if (QuestionType::VraiFaux === $type && \count($options) < 2) {
            $options = [$this->translator->trans('answerTrueLabel'), $this->translator->trans('answerFalseLabel')];
        }

        if (\count($options) < 2) {
            throw new \InvalidArgumentException('simpleImportQuestionNotEnoughAnswersError');
        }
        if ([] === $correct) {
            throw new \InvalidArgumentException('simpleImportQuestionNoCorrectError');
        }
        foreach ($correct as $index) {
            if ($index < 1 || $index > \count($options)) {
                throw new \InvalidArgumentException('simpleImportQuestionCorrectOutOfRangeError');
            }
        }
        if (\in_array($type, [QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image], true) && 1 !== \count($correct)) {
            throw new \InvalidArgumentException('simpleImportQuestionSingleCorrectExpectedError');
        }

        if (QuestionType::Ordre === $type) {
            if (\count($correct) !== \count($options) || \count(array_unique($correct)) !== \count($options)) {
                throw new \InvalidArgumentException('simpleImportQuestionOrderIncompleteError');
            }

            // Stored *in* the expected sequence, like QuizCsvImporter's own reordering.
            $question['answers'] = array_map(
                static fn (int $index): array => ['label' => $options[$index - 1], 'correct' => false],
                $correct,
            );

            return $question;
        }

        $question['answers'] = array_map(
            static fn (int $position, string $option): array => ['label' => $option, 'correct' => \in_array($position + 1, $correct, true)],
            array_keys($options),
            $options,
        );

        return $question;
    }

    /**
     * @param SimpleImportQuestion    $question
     * @param array<array-key, mixed> $raw
     *
     * @return SimpleImportQuestion
     */
    private function parseBlanks(array $question, array $raw): array
    {
        $blankCount = BlankTextParser::countBlanks($question['label']);
        if (0 === $blankCount) {
            throw new \InvalidArgumentException('simpleImportQuestionNoBlankError');
        }

        $blanks = [];
        foreach (\is_array($raw['blanks'] ?? null) ? $raw['blanks'] : [] as $entry) {
            $variants = $this->stringListOf(\is_array($entry) ? $entry : [$entry]);
            if ([] === $variants) {
                throw new \InvalidArgumentException('simpleImportQuestionEmptyBlankError');
            }
            $blanks[] = $variants;
        }

        if ($blankCount !== \count($blanks)) {
            throw new \InvalidArgumentException('simpleImportQuestionBlankCountError');
        }

        $question['blanks'] = $blanks;
        $question['blankMode'] = (BlankMode::tryFrom($this->stringOf($raw['mode'] ?? null) ?? '') ?? BlankMode::Banque)->value;

        return $question;
    }

    /**
     * "bonnes", as a list of 1-based indices. A vrai/faux also accepts the boolean a model naturally
     * writes for an assertion - there is nothing to guess about `true`, unlike a timecode.
     *
     * @return list<int>
     */
    private function correctIndexesOf(mixed $value, QuestionType $type): array
    {
        if (\is_bool($value)) {
            return QuestionType::VraiFaux === $type ? [$value ? 1 : 2] : [];
        }

        $indexes = [];
        foreach (\is_array($value) ? $value : [$value] as $item) {
            if (is_numeric($item)) {
                $indexes[] = (int) $item;
            }
        }

        return $indexes;
    }

    private function stringOf(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    /** @return list<string> */
    private function stringListOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_scalar($item) && '' !== trim((string) $item)) {
                $items[] = trim((string) $item);
            }
        }

        return $items;
    }
}
