<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAnswerDefinition;
use App\Entity\QuizQuestionDefinition;
use App\Enum\QuestionType;
use App\Util\NumericVariableParser;

/**
 * One question, as the mobile app reads it - the twelve types described in one place.
 *
 * It was a private method of App\Controller\Api\QuizController until the interactive video needed
 * the same description: the app plays the same twelve types inside a video as inside a quiz, and
 * two builders would be two descriptions of one contract, free to drift on the day a thirteenth
 * type arrives.
 *
 * **Everything the payload does NOT carry is deliberate.** The correct ids, the accepted variants,
 * the `right` side of a matching pair, the expected numeric value and the formula are answers, and
 * they reach the app at correction time and not before. What the payload does carry pre-computed -
 * the split blanks, the parsed zones, the rendered numeric statement - is there so the app never
 * re-implements a rule the grader owns.
 *
 * Its inputs are already resolved on purpose: the ordering of answers and choices differs between a
 * quiz (seeded per attempt, App\Service\QuizDrawService) and a video marker (plainly shuffled), and
 * that difference belongs to the caller, not here.
 */
class QuizQuestionPayload
{
    public function __construct(private readonly FileUploadService $fileUploadService)
    {
    }

    /**
     * @param list<QuizAnswerDefinition>                        $answers        already in the order the student will see
     * @param list<string>                                      $wordBank       ordered
     * @param list<array{key: string, text: string}>            $zoneChoices    ordered
     * @param list<array<string, mixed>>                         $matchingPairs  ordered, answers still on them
     * @param list<array{key: string, text: string, image: ?string}> $matchingChoices ordered
     * @param array<string, float>                              $numericVariables this student's draw
     * @param bool                                              $withHints      entraînement only
     *
     * @return array<string, mixed>
     */
    public function build(
        QuizQuestionDefinition $question,
        array $answers,
        array $wordBank,
        array $zoneChoices,
        array $matchingPairs,
        array $matchingChoices,
        array $numericVariables,
        bool $withHints,
    ): array {
        $isBlanks = QuestionType::TexteATrous === $question->getType();
        $isZones = $question->getType()->usesZoneConfig();
        $isMatching = QuestionType::Apparier === $question->getType();
        $isNumeric = $question->getType()->usesNumericConfig();

        return [
            'type' => $question->getType()->value,
            'label' => $question->getLabel(),
            'imageUrl' => null !== $question->getImageStorageKey() ? $this->fileUploadService->url($question->getImageStorageKey()) : null,
            'answers' => array_map(
                static fn (QuizAnswerDefinition $answer): array => ['id' => $answer->getId(), 'label' => $answer->getLabel()],
                $answers,
            ),
            // Texte à trous ships the statement pre-split, so the app never has to re-implement the
            // "..." parsing rules (App\Util\BlankTextParser) and drift from the server's blank count.
            'blankMode' => $isBlanks ? $question->getBlankMode()->value : null,
            'blankSegments' => $isBlanks ? $question->getBlankSegments() : null,
            'wordBank' => $isBlanks ? $wordBank : null,
            // What the matching forgives, for both typed-answer types: the app tells the student,
            // who otherwise cannot know how carefully to type. The accepted variants themselves
            // stay out - they are the answer, and only reach the app at correction time.
            'blankIgnoreCase' => $question->getType()->usesBlankAnswers() ? $question->isIgnoreCase() : null,
            'blankTolerateTypo' => $question->getType()->usesBlankAnswers() ? $question->isTolerateTypo() : null,
            // Zone/Légende ship the support pre-parsed for the same reason - the app renders
            // lines/segments/rectangles, it never re-implements the [[id|texte]] markers
            // (App\Util\ZoneTextParser). Correct ids and feedbacks are deliberately absent here.
            'zoneKind' => $isZones ? $question->getZoneKind()->value : null,
            'zoneLanguage' => $isZones ? $question->getZoneLanguage() : null,
            'zoneLines' => $isZones ? $question->getZoneLines() : null,
            'imageZones' => $isZones ? $question->getImageZones() : null,
            'zoneChoices' => QuestionType::Legende === $question->getType() ? $zoneChoices : null,
            // Same rule as the web: the hint only exists in entraînement, and Zone questions with
            // several targets say so ("cliquez les zones" vs "la zone").
            'zoneHintIds' => QuestionType::Zone === $question->getType() && $withHints ? $question->getZoneHintIds() : [],
            'zoneMultiple' => QuestionType::Zone === $question->getType() ? \count($question->getZoneCorrectIds()) > 1 : null,
            // Apparier ships the left column and the pool of choices, both already shuffled. The
            // pairs are stripped of their `right` side on purpose: that side IS the answer, and it
            // reaches the app only at correction time.
            'matchingHeaders' => $isMatching ? $question->getMatchingHeaders() : null,
            'matchingLeftKind' => $isMatching ? $question->getMatchingLeftKind()->value : null,
            'matchingRightKind' => $isMatching ? $question->getMatchingRightKind()->value : null,
            'matchingPairs' => $isMatching ? array_map(
                fn (array $pair): array => $this->matchingPair($pair, withAnswer: false),
                $matchingPairs,
            ) : null,
            'matchingChoices' => $isMatching ? array_map($this->matchingChoice(...), $matchingChoices) : null,
            // Numérique / Calculée. The statement reaches the app already rendered with this
            // student's own values, for the same reason the blanks and the zones ship pre-split:
            // the app must never re-implement a rule the grader owns. The expected value and the
            // formula are deliberately absent - they are the answer.
            'numericStatement' => $isNumeric ? NumericVariableParser::render((string) $question->getLabel(), $this->formattedVariables($question, $numericVariables)) : null,
            'numericUnit' => $isNumeric ? $question->getNumericUnit() : null,
            'numericUnitRequired' => $isNumeric ? $question->isNumericUnitRequired() : null,
        ];
    }

    /**
     * @param array<string, mixed> $pair
     *
     * @return array<string, mixed>
     */
    public function matchingPair(array $pair, bool $withAnswer = true): array
    {
        $payload = [
            'id' => $pair['id'],
            // Kept even on an image column: it is the item's alternative text.
            'left' => $pair['left'],
            'leftImageUrl' => null !== $pair['leftImage'] ? $this->fileUploadService->url($pair['leftImage']) : null,
        ];

        if ($withAnswer) {
            $payload['right'] = $pair['right'];
            $payload['rightImageUrl'] = null !== $pair['rightImage'] ? $this->fileUploadService->url($pair['rightImage']) : null;
        }

        return $payload;
    }

    /**
     * @param array{key: string, text: string, image: ?string} $choice
     *
     * @return array<string, mixed>
     */
    public function matchingChoice(array $choice): array
    {
        return [
            'key' => $choice['key'],
            'text' => $choice['text'],
            'imageUrl' => null !== $choice['image'] ? $this->fileUploadService->url($choice['image']) : null,
        ];
    }

    /**
     * The drawn values as the statement shows them - each rounded to its own variable's decimals,
     * so "{v}" reads "120" and not "120.0".
     *
     * French formatting, comma and thin space: the statement is read by a French classroom, and the
     * value the student sees must be the value they would write down.
     *
     * @param array<string, float> $variables
     *
     * @return array<string, string>
     */
    public function formattedVariables(QuizQuestionDefinition $question, array $variables): array
    {
        $decimals = [];
        foreach ($question->getNumericVariables() as $variable) {
            $decimals[$variable['name']] = $variable['decimals'];
        }

        $formatted = [];
        foreach ($variables as $name => $value) {
            $formatted[$name] = number_format($value, $decimals[$name] ?? 0, ',', ' ');
        }

        return $formatted;
    }
}
