<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizQuestionDefinition;
use App\Enum\QuestionType;

/**
 * Whether a student's submitted answer(s) for one question are correct, and what they earn - see
 * App\Entity\QuizAttemptAnswer's class docblock (grading is computed once, at answer time, and
 * frozen). Grading rule per App\Enum\QuestionType:
 * - qcm / vrai_faux / image: exactly one selection, matching the question's single correct answer.
 * - qcm_multi: the selected set must exactly equal the correct set (no partial credit - picking 3
 *   of 4 correct answers, or 2 correct plus 1 wrong, both grade as incorrect).
 * - ordre: the submitted sequence must exactly match every answer's true QuizInstanceAnswer::$orderIndex.
 * - texte_a_trous / legende / apparier: the partial-credit types - each blank, zone or pair is
 *   graded on its own and the question's points are split equally between them (screens 2a-2d of
 *   design/design_handoff_quiz). isCorrect() stays "every one of them right", so the green/red
 *   badges keep their all-or-nothing meaning.
 * - zone: all-or-nothing on the clicked set, but weighted by the question's own barème.
 */
class QuizAttemptGrader
{
    public function __construct(private readonly QuizAnswerChecker $checker)
    {
    }

    /**
     * @param list<int>                $selectedInstanceAnswerIds in submission order (order only matters for "ordre" questions)
     * @param list<string>             $blankResponses            what was typed/placed per blank - texte à trous only
     * @param array<array-key, string> $zoneResponses             Zone: clicked zone ids; Legende: zone id => placed choice key
     * @param array<array-key, string> $matchingResponses         Apparier: pair id => picked choice key
     * @param array<string, float>     $numericVariables          Calculee: the values this student was drawn
     */
    public function isCorrect(QuizInstanceQuestion $question, array $selectedInstanceAnswerIds, array $blankResponses = [], array $zoneResponses = [], array $matchingResponses = [], ?float $numericValue = null, ?string $numericUnit = null, array $numericVariables = []): bool
    {
        // Texte à trous, the zones types and apparier have no answer rows at all - their
        // correctness lives entirely in the trait's JSON configs - so their collection is
        // deliberately not touched here.
        $answers = $question->getType()->usesAnswerRows() ? $this->answerRows($question) : [];

        return $this->checker->isCorrect($question, $answers, $selectedInstanceAnswerIds, $blankResponses, $zoneResponses, $matchingResponses, $numericValue, $numericUnit, $numericVariables);
    }

    /**
     * The points earned. Rounded to 2 decimals to match the column it is stored in, so summing an
     * attempt's answers can never drift from the value each row shows.
     *
     * @param list<int>                $selectedInstanceAnswerIds
     * @param list<string>             $blankResponses
     * @param array<array-key, string> $zoneResponses
     * @param array<array-key, string> $matchingResponses
     * @param array<string, float>     $numericVariables
     */
    public function score(QuizInstanceQuestion $question, array $selectedInstanceAnswerIds, array $blankResponses = [], array $zoneResponses = [], array $matchingResponses = [], ?float $numericValue = null, ?string $numericUnit = null, array $numericVariables = []): float
    {
        if (QuestionType::TexteATrous === $question->getType()) {
            $results = $this->blankResults($question, $blankResponses);

            return [] === $results ? 0.0 : round($question->getPoints() * \count(array_filter($results)) / \count($results), 2);
        }

        if (QuestionType::Legende === $question->getType()) {
            // Same partial-credit rule as the blanks: the question's points split equally between
            // its zones, each graded on its own.
            $results = $this->checker->zoneResults($question, $zoneResponses);

            return [] === $results ? 0.0 : round($question->getPoints() * \count(array_filter($results)) / \count($results), 2);
        }

        if (QuestionType::Apparier === $question->getType()) {
            // Partial credit again, per pair: getting 3 of 4 associations right is worth 3 quarters
            // of the question, and a 12-pair question graded all-or-nothing would be unanswerable.
            $results = $this->checker->matchingResults($question, $matchingResponses);

            return [] === $results ? 0.0 : round($question->getPoints() * \count(array_filter($results)) / \count($results), 2);
        }

        if ($question->getType()->usesNumericConfig()) {
            // All-or-nothing like a Zone, and weighted the same way: a number is right or it is not,
            // and the tolerance is already where the leniency lives.
            return $this->isCorrect($question, [], [], [], [], $numericValue, $numericUnit, $numericVariables)
                ? $question->getPoints()
                : 0.0;
        }

        if (QuestionType::Zone === $question->getType()) {
            // All-or-nothing like a QcmMulti, but weighted: a Zone question carries a
            // teacher-settable barème exactly like the other config-driven types.
            return $this->isCorrect($question, [], [], $zoneResponses) ? $question->getPoints() : 0.0;
        }

        return $this->isCorrect($question, $selectedInstanceAnswerIds) ? 1.0 : 0.0;
    }

    /**
     * Per-blank correctness in text order - drives both the score above and the green/red rendering
     * of each blank on the entraînement correction screen (1m).
     *
     * @param list<string> $responses
     *
     * @return list<bool> empty when the question is not a texte à trous, or has no blank at all
     */
    public function blankResults(QuizQuestionDefinition $question, array $responses): array
    {
        return $this->checker->blankResults($question, $responses);
    }

    /**
     * Per-zone correctness of a Légende question, keyed by zone id - same role as blankResults()
     * one type over.
     *
     * @param array<array-key, string> $placements
     *
     * @return array<string, bool> empty when the question is not a Légende
     */
    public function zoneResults(QuizQuestionDefinition $question, array $placements): array
    {
        return $this->checker->zoneResults($question, $placements);
    }

    /**
     * Per-pair correctness of an Apparier question, keyed by pair id - same role again, one type
     * over.
     *
     * @param array<array-key, string> $associations
     *
     * @return array<string, bool> empty when the question is not an Apparier
     */
    public function matchingResults(QuizQuestionDefinition $question, array $associations): array
    {
        return $this->checker->matchingResults($question, $associations);
    }

    /**
     * What the question expected of this student - the teacher's value, or their own formula
     * result. The correction screens print it, so they go through the grader rather than
     * re-evaluating the formula themselves.
     *
     * @param array<string, float> $variables
     */
    public function expectedNumericValue(QuizQuestionDefinition $question, array $variables = []): ?float
    {
        return $this->checker->expectedNumericValue($question, $variables);
    }

    /** @param array<string, float> $variables */
    public function numericMargin(QuizQuestionDefinition $question, array $variables = []): ?float
    {
        $expected = $this->expectedNumericValue($question, $variables);

        return null === $expected ? null : $this->checker->numericMargin($question, $expected);
    }

    /**
     * The answers reduced to what grading needs. QuizAnswerChecker works on this rather than on the
     * entity so the library preview, which holds QuizAnswer instead of QuizInstanceAnswer, can call
     * the very same rule.
     *
     * @return list<array{id: int, correct: bool, orderIndex: int}>
     */
    private function answerRows(QuizInstanceQuestion $question): array
    {
        return array_values(array_map(
            static fn (QuizInstanceAnswer $answer): array => [
                'id' => $answer->getId(),
                'correct' => $answer->isCorrect(),
                // The template-defined order, never the shuffled order this student saw it in
                // (see QuizDrawService::orderAnswers()).
                'orderIndex' => $answer->getOrderIndex(),
            ],
            $question->getAnswers()->toArray(),
        ));
    }
}
