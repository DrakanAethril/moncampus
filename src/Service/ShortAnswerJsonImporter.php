<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\BlankMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-reponse-courte/1" import format - the paste-a-JSON way into Réponse courte
 * questions, fourth family of the interactive import screen.
 *
 * This is the family where an assistant is most obviously worth asking, and for one reason: the
 * hard part of a short-answer question is not the question, it is *the list of accepted variants*.
 * A teacher writing "photosynthèse" and moving on has built a question that marks half their class
 * wrong over "la photosynthèse"; a model asked for the variants produces the singular, the plural,
 * the abbreviation and the form with the article in one go. The prompt on the screen says so, and
 * the check below refuses a question that came back with a single spelling of a multi-word answer.
 *
 * Same contract as its three siblings (App\Service\InteractiveQuizImporter).
 *
 * @phpstan-type ShortAnswerImportQuestion array{
 *     type: string,
 *     difficulty: ?string,
 *     label: string,
 *     explanation: ?string,
 *     points: float,
 *     blanksConfig: array<string, mixed>,
 * }
 */
final class ShortAnswerJsonImporter implements InteractiveQuizImporter
{
    public const string FORMAT = 'moncampus-reponse-courte/1';

    // Same ceiling and same reasoning as QuizCsvImporter::MAX_QUESTIONS.
    public const int MAX_QUESTIONS = 500;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function family(): string
    {
        return 'reponse-courte';
    }

    public function formatTag(): string
    {
        return self::FORMAT;
    }

    public function payloadFormat(): string
    {
        return 'short-answer';
    }

    public function handles(QuestionType $type): bool
    {
        return QuestionType::ReponseCourte === $type;
    }

    public function exampleLabels(): array
    {
        return ShortAnswerExampleCatalog::labels();
    }

    public function exampleJson(string $key): ?string
    {
        return ShortAnswerExampleCatalog::json($key);
    }

    /**
     * @return array{format: 'short-answer', name: string, subject: ?string, description: ?string, fileName: string, questions: list<ShortAnswerImportQuestion>, errors: list<string>}
     *
     * @throws QuizCsvImportException when the document as a whole is unusable
     */
    public function parse(string $json, string $fileName = 'import.json', int $firstNumber = 1): array
    {
        try {
            $document = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QuizCsvImportException('shortAnswerImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new QuizCsvImportException('shortAnswerImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new QuizCsvImportException('shortAnswerImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $rawQuestions = \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        if ([] === $rawQuestions) {
            throw new QuizCsvImportException('shortAnswerImportNoQuestionError');
        }
        if (\count($rawQuestions) > self::MAX_QUESTIONS) {
            throw new QuizCsvImportException('shortAnswerImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
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
            'format' => 'short-answer',
            'name' => $this->stringOf($template['name'] ?? null) ?? $this->translator->trans('shortAnswerImportDefaultTemplateName'),
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
        $orderIndex = $template->getQuestions()->count();

        foreach (array_values($questions) as $raw) {
            if (!\is_array($raw)) {
                continue;
            }

            $question = new QuizQuestion($template);
            $question->setType(QuestionType::ReponseCourte);
            $question->setDifficulty(QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? ''));
            $question->setLabel($this->stringOf($raw['label'] ?? null) ?? '');
            $question->setExplanation($this->stringOf($raw['explanation'] ?? null));
            $question->setPoints(is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0);
            $question->setBlanksConfig(\is_array($raw['blanksConfig'] ?? null) ? $raw['blanksConfig'] : null);
            $question->setOrderIndex(++$orderIndex);

            $template->addQuestion($question);
        }
    }

    /**
     * One question, as this format writes it - null for a question of another reader's types.
     *
     * @return array<string, mixed>|null
     */
    public function exportQuestion(QuizQuestion $question): ?array
    {
        if (QuestionType::ReponseCourte !== $question->getType()) {
            return null;
        }

        $item = [
            'type' => $question->getType()->value,
            'label' => (string) $question->getLabel(),
            'difficulty' => $question->getDifficulty()?->value,
            'points' => $question->getPoints(),
            'answers' => $question->getBlankAnswers()[0] ?? [],
            'ignoreCase' => $question->isIgnoreCase(),
            'tolerateTypo' => $question->isTolerateTypo(),
        ];
        if (null !== $question->getExplanation()) {
            $item['explanation'] = $question->getExplanation();
        }

        return $item;
    }

    /**
     * The reverse direction - a template's Réponse courte questions back out as a
     * "moncampus-reponse-courte/1" document.
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
     * @return ShortAnswerImportQuestion
     *
     * @throws \InvalidArgumentException carrying the error's translation key
     */
    private function parseQuestion(array $raw): array
    {
        $type = QuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '');
        // "type" is accepted but redundant - this format carries one type.
        if (null !== $type && QuestionType::ReponseCourte !== $type) {
            throw new \InvalidArgumentException('shortAnswerImportQuestionBadTypeError');
        }

        $label = $this->stringOf($raw['label'] ?? null);
        if (null === $label) {
            throw new \InvalidArgumentException('shortAnswerImportQuestionNoLabelError');
        }

        $answers = $this->stringListOf($raw['answers'] ?? null);
        if ([] === $answers) {
            throw new \InvalidArgumentException('shortAnswerImportQuestionNoAnswerError');
        }

        // Stored as a texte à trous with exactly one blank - see
        // App\Entity\QuizQuestionDefinitionTrait, which is what lets the whole blanks machinery
        // grade this type unchanged.
        $config = [
            'mode' => BlankMode::Libre->value,
            'blanks' => [['answers' => $answers]],
            'ignoreCase' => (bool) ($raw['ignoreCase'] ?? true),
            'tolerateTypo' => (bool) ($raw['tolerateTypo'] ?? false),
        ];

        $points = is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0;

        return [
            'type' => QuestionType::ReponseCourte->value,
            'difficulty' => QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? '')?->value,
            'label' => $label,
            'explanation' => $this->stringOf($raw['explanation'] ?? null),
            'points' => $points > 0 ? $points : 1.0,
            'blanksConfig' => $config,
        ];
    }

    private function stringOf(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    /**
     * The accepted variants, trimmed and de-duplicated. De-duplication is case- and accent-blind
     * when the question forgives those anyway: "Photosynthèse" and "photosynthese" as two entries
     * of the same list is not a variant, it is noise in the correction listing.
     *
     * @return list<string>
     */
    private function stringListOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $variants = [];
        $seen = [];
        foreach ($value as $item) {
            if (!\is_scalar($item) || '' === trim((string) $item)) {
                continue;
            }
            $variant = trim((string) $item);
            $key = mb_strtolower($variant);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $variants[] = $variant;
        }

        return $variants;
    }
}
