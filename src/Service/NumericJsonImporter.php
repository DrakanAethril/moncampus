<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Enum\ToleranceMode;
use App\Util\FormulaEvaluator;
use App\Util\NumericVariableParser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-numerique/1" import format - the paste-a-JSON way into Numérique and Calculée
 * questions, sibling of App\Service\ZoneJsonImporter and App\Service\MatchingJsonImporter and built
 * on the same idea: the teacher hands an assistant the format spec plus their course material, pastes
 * the answer back, and reviews the preview before anything is written.
 *
 * This is the family where the import earns the most: a calculée is a statement, a formula, and
 * three or four ranges, all of which a model writes far faster than a teacher fills four fields
 * fifteen times - and all of which are checkable here rather than discovered by a student.
 *
 * Validation is two-tiered like its siblings, but the hard tier is longer, because a numeric
 * question has more ways of being silently unanswerable than a wrong one:
 * - no expected value, or no formula                          -> the question cannot be marked
 * - a formula that does not parse                             -> same
 * - a formula reading a name the variables do not draw        -> evaluates to nothing, for everyone
 * - a variable drawn but absent from the statement            -> the student is asked to compute
 *                                                                with a number they were never shown
 * - a formula that does not evaluate at the middle of every
 *   range                                                     -> a division by zero waiting for
 *                                                                whoever draws it
 * Everything else (tolerance, unit, decimals, points) falls back to a sane default instead.
 *
 * @phpstan-type NumericImportQuestion array{
 *     type: string,
 *     difficulty: ?string,
 *     label: string,
 *     explanation: ?string,
 *     points: float,
 *     numericConfig: array<string, mixed>,
 * }
 */
final class NumericJsonImporter implements InteractiveQuizImporter
{
    public const string FORMAT = 'moncampus-numerique/1';

    // Same ceiling and same reasoning as QuizCsvImporter::MAX_QUESTIONS.
    public const int MAX_QUESTIONS = 500;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function family(): string
    {
        return 'numerique';
    }

    public function formatTag(): string
    {
        return self::FORMAT;
    }

    public function payloadFormat(): string
    {
        return 'numeric';
    }

    public function handles(QuestionType $type): bool
    {
        return $type->usesNumericConfig();
    }

    public function exampleLabels(): array
    {
        return NumericExampleCatalog::labels();
    }

    public function exampleJson(string $key): ?string
    {
        return NumericExampleCatalog::json($key);
    }

    /**
     * @return array{format: 'numeric', name: string, subject: ?string, description: ?string, fileName: string, questions: list<NumericImportQuestion>, errors: list<string>}
     *
     * @throws QuizCsvImportException when the document as a whole is unusable
     */
    public function parse(string $json, string $fileName = 'import.json', int $firstNumber = 1): array
    {
        try {
            $document = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QuizCsvImportException('numericImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new QuizCsvImportException('numericImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new QuizCsvImportException('numericImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $rawQuestions = \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        if ([] === $rawQuestions) {
            throw new QuizCsvImportException('numericImportNoQuestionError');
        }
        if (\count($rawQuestions) > self::MAX_QUESTIONS) {
            throw new QuizCsvImportException('numericImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
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
            'format' => 'numeric',
            'name' => $this->stringOf($template['name'] ?? null) ?? $this->translator->trans('numericImportDefaultTemplateName'),
            'subject' => $this->stringOf($template['subject'] ?? null),
            'description' => $this->stringOf($template['description'] ?? null),
            'fileName' => $fileName,
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    /**
     * Builds the confirmed questions onto $template. Nothing to re-upload here - a numeric question
     * is text and numbers - so $copyImages is accepted for the interface and ignored.
     *
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
            $question->setType(QuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '') ?? QuestionType::Numerique);
            $question->setDifficulty(QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? ''));
            $question->setLabel($this->stringOf($raw['label'] ?? null) ?? '');
            $question->setExplanation($this->stringOf($raw['explanation'] ?? null));
            $question->setPoints(is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0);
            $question->setNumericConfig(\is_array($raw['numericConfig'] ?? null) ? $raw['numericConfig'] : null);
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
        if (!$question->getType()->usesNumericConfig()) {
            return null;
        }

        $item = [
            'type' => $question->getType()->value,
            'label' => (string) $question->getLabel(),
            'difficulty' => $question->getDifficulty()?->value,
            'points' => $question->getPoints(),
            'tolerance' => $question->getNumericTolerance(),
            'toleranceMode' => $question->getNumericToleranceMode()->value,
            'decimals' => $question->getNumericDecimals(),
        ];

        if ($question->getType()->usesFormula()) {
            $item['formula'] = $question->getNumericFormula();
            $item['variables'] = $question->getNumericVariables();
        } else {
            $item['answer'] = $question->getNumericAnswer();
        }

        if (null !== $question->getNumericUnit()) {
            $item['unit'] = $question->getNumericUnit();
            $item['unitRequired'] = $question->isNumericUnitRequired();
        }
        if (null !== $question->getExplanation()) {
            $item['explanation'] = $question->getExplanation();
        }

        return $item;
    }

    /**
     * The reverse direction - a template's numeric questions back out as a "moncampus-numerique/1"
     * document, for sharing between teachers and re-importing.
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
     * @return NumericImportQuestion
     *
     * @throws \InvalidArgumentException carrying the error's translation key
     */
    private function parseQuestion(array $raw): array
    {
        $type = QuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '');
        if (null === $type || !$type->usesNumericConfig()) {
            throw new \InvalidArgumentException('numericImportQuestionBadTypeError');
        }

        $label = $this->stringOf($raw['label'] ?? null);
        if (null === $label) {
            throw new \InvalidArgumentException('numericImportQuestionNoLabelError');
        }

        $config = [
            'tolerance' => max(0.0, is_numeric($raw['tolerance'] ?? null) ? (float) $raw['tolerance'] : 2.0),
            'toleranceMode' => (ToleranceMode::tryFrom($this->stringOf($raw['toleranceMode'] ?? null) ?? '') ?? ToleranceMode::Percent)->value,
            'decimals' => max(0, min(6, is_numeric($raw['decimals'] ?? null) ? (int) $raw['decimals'] : 2)),
        ];

        $unit = $this->stringOf($raw['unit'] ?? null);
        if (null !== $unit) {
            $config['unit'] = $unit;
            $config['unitRequired'] = (bool) ($raw['unitRequired'] ?? false);
        }

        if ($type->usesFormula()) {
            $config += $this->calculatedConfig($raw, $label);
        } else {
            if (!is_numeric($raw['answer'] ?? null)) {
                throw new \InvalidArgumentException('numericImportQuestionNoAnswerError');
            }
            $config['answer'] = (float) $raw['answer'];
        }

        $points = is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0;

        return [
            'type' => $type->value,
            'difficulty' => QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? '')?->value,
            'label' => $label,
            'explanation' => $this->stringOf($raw['explanation'] ?? null),
            'points' => $points > 0 ? $points : 1.0,
            'numericConfig' => $config,
        ];
    }

    /**
     * The calculée half: the formula and the ranges, checked against each other and against the
     * statement. Every failure here produces a question no student could ever answer, which is why
     * they are all fatal to the question rather than dropped decoration.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{formula: string, variables: list<array{name: string, min: float, max: float, step: float, decimals: int}>}
     */
    private function calculatedConfig(array $raw, string $label): array
    {
        $formula = $this->stringOf($raw['formula'] ?? null);
        if (null === $formula) {
            throw new \InvalidArgumentException('numericImportQuestionNoFormulaError');
        }
        if (!FormulaEvaluator::isSyntaxValid($formula)) {
            throw new \InvalidArgumentException('numericImportQuestionBadFormulaError');
        }

        $variables = $this->variablesOf($raw['variables'] ?? null);
        if ([] === $variables) {
            throw new \InvalidArgumentException('numericImportQuestionNoVariableError');
        }

        $drawn = array_column($variables, 'name');

        // Every name the formula reads has to be drawn, or it evaluates to nothing for everyone.
        if ([] !== array_diff(FormulaEvaluator::variableNames($formula), $drawn)) {
            throw new \InvalidArgumentException('numericImportQuestionFormulaUnknownNameError');
        }

        // And every drawn variable has to appear in the statement, or the student is asked to
        // compute with a number nobody ever showed them.
        if ([] !== array_diff($drawn, NumericVariableParser::names($label))) {
            throw new \InvalidArgumentException('numericImportQuestionVariableNotInStatementError');
        }

        // Finally: does it actually compute? Probed at the middle of every range, which is what the
        // "Tester" tab shows a teacher and what catches a division by a variable that spans zero.
        $midpoints = [];
        foreach ($variables as $variable) {
            $midpoints[$variable['name']] = ($variable['min'] + $variable['max']) / 2;
        }
        if (null === FormulaEvaluator::evaluate($formula, $midpoints)) {
            throw new \InvalidArgumentException('numericImportQuestionFormulaDoesNotComputeError');
        }

        return ['formula' => $formula, 'variables' => $variables];
    }

    /** @return list<array{name: string, min: float, max: float, step: float, decimals: int}> */
    private function variablesOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $variables = [];
        $seen = [];
        foreach ($value as $variable) {
            if (!\is_array($variable)) {
                continue;
            }
            $name = $this->stringOf($variable['name'] ?? null);
            if (null === $name || isset($seen[$name]) || !is_numeric($variable['min'] ?? null) || !is_numeric($variable['max'] ?? null)) {
                continue;
            }

            $min = (float) $variable['min'];
            $max = (float) $variable['max'];
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            $decimals = max(0, min(6, is_numeric($variable['decimals'] ?? null) ? (int) $variable['decimals'] : 0));
            $step = is_numeric($variable['step'] ?? null) ? abs((float) $variable['step']) : 1.0;

            $seen[$name] = true;
            $variables[] = [
                'name' => $name,
                'min' => $min,
                'max' => $max,
                'step' => $step > 0 ? $step : 10 ** -$decimals,
                'decimals' => $decimals,
            ];
        }

        return $variables;
    }

    private function stringOf(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}
