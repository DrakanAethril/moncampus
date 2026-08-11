<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestionDefinition;
use App\Enum\QuestionType;
use App\Enum\ToleranceMode;
use App\Util\BlankTextParser;
use App\Util\FormulaEvaluator;
use App\Util\NumericAnswerParser;

/**
 * "Is this answer right?", for every question type - the single rule behind both a real attempt and
 * the library's "Tester" preview.
 *
 * It used to exist twice: App\Service\QuizAttemptGrader graded attempts on QuizInstanceQuestion /
 * QuizInstanceAnswer, and App\Controller\QuizLibraryController repeated it inline on QuizQuestion /
 * QuizAnswer for the preview, under a comment promising the two matched "exactly". Nothing enforced
 * that, and a divergence is the worst kind for this app: the preview telling a teacher their
 * question works while a student's attempt marks the same answer wrong.
 *
 * Answers arrive reduced to what grading actually needs - an id, whether it is correct, and its
 * position - which is the only reason one rule can serve both entity families. Everything else
 * comes off QuizQuestionDefinition, the interface both question entities already implement.
 */
final class QuizAnswerChecker
{
    /**
     * @param list<array{id: int, correct: bool, orderIndex: int}> $answers           the question's own answers, any order
     * @param list<int>                                            $selectedIds       in submission order (which only matters for "ordre")
     * @param list<string>                                         $blankResponses    in text order
     * @param array<array-key, string>                             $zoneResponses     Zone: the clicked zone ids; Legende: zone id => placed choice key
     * @param array<array-key, string>                             $matchingResponses Apparier: pair id => picked choice key
     * @param array<string, float>                                 $numericVariables  Calculee: the values this student was drawn
     */
    public function isCorrect(QuizQuestionDefinition $question, array $answers, array $selectedIds, array $blankResponses = [], array $zoneResponses = [], array $matchingResponses = [], ?float $numericValue = null, ?string $numericUnit = null, array $numericVariables = []): bool
    {
        return match ($question->getType()) {
            QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image => $this->isSingleCorrect($answers, $selectedIds),
            QuestionType::QcmMulti => $this->isMultiCorrect($answers, $selectedIds),
            QuestionType::Ordre => $this->isOrderCorrect($answers, $selectedIds),
            QuestionType::TexteATrous, QuestionType::ReponseCourte => $this->areBlanksCorrect($question, $blankResponses),
            QuestionType::Zone => $this->isZoneSelectionCorrect($question, $zoneResponses),
            QuestionType::Legende => $this->areLegendePlacementsCorrect($question, $zoneResponses),
            QuestionType::Apparier => $this->areMatchingsCorrect($question, $matchingResponses),
            QuestionType::Numerique, QuestionType::Calculee => $this->isNumericCorrect($question, $numericValue, $numericUnit, $numericVariables),
        };
    }

    /**
     * What the question expects of *this* student: the teacher's value for a numérique, the formula
     * evaluated over their own drawn variables for a calculée. Null when the question cannot be
     * answered at all - no value written, no formula, or a formula that does not evaluate - which
     * the caller reads as "nobody can get this right", the same rule an answerless QCM follows.
     *
     * @param array<string, float> $variables the values this student was drawn
     */
    public function expectedNumericValue(QuizQuestionDefinition $question, array $variables = []): ?float
    {
        if (!$question->getType()->usesFormula()) {
            return $question->getNumericAnswer();
        }

        $formula = $question->getNumericFormula();
        if (null === $formula) {
            return null;
        }

        return FormulaEvaluator::evaluate($formula, $variables);
    }

    /**
     * How far the answer may be from the expected value, as an absolute distance. A percentage is
     * taken of the expected value's magnitude, which is what "à 2 % près" means and what keeps a
     * calculée fair when each student's own value differs.
     *
     * On an expected value of exactly zero a percentage is zero too, so only an exact 0 passes.
     * That is deliberate rather than an oversight: reading the 2 as an absolute margin would accept
     * 1.9 on a question whose answer might be measured in millimetres. The editor says so.
     */
    public function numericMargin(QuizQuestionDefinition $question, float $expected): float
    {
        $tolerance = $question->getNumericTolerance();

        return ToleranceMode::Percent === $question->getNumericToleranceMode()
            ? abs($expected) * $tolerance / 100
            : $tolerance;
    }

    /** @param array<string, float> $variables */
    private function isNumericCorrect(QuizQuestionDefinition $question, ?float $value, ?string $unit, array $variables): bool
    {
        if (null === $value) {
            return false;
        }

        $expected = $this->expectedNumericValue($question, $variables);
        if (null === $expected) {
            return false;
        }

        // The unit is only ever part of the answer when the teacher asked for it; otherwise it is a
        // fixed suffix beside the field and whatever the student typed after their number is noise.
        if ($question->isNumericUnitRequired() && !NumericAnswerParser::unitsMatch($question->getNumericUnit(), $unit)) {
            return false;
        }

        // Compared with a hair of slack, because the boundary is exactly where binary floating
        // point bites: 240 + 2 % is 244.8, but 244.8 - 240 computes as 4.800000000000011, and a
        // student typing the exact edge of the tolerance would be marked wrong by the last bit of a
        // double. The slack scales with the magnitude so it stays negligible at any scale.
        $margin = $this->numericMargin($question, $expected);
        $epsilon = 1e-9 * max(1.0, abs($expected), abs($margin));

        return abs($value - $expected) <= $margin + $epsilon;
    }

    /**
     * Per-pair correctness of an Apparier question, keyed by pair id - drives the partial score and
     * the green/red rendering of each row on the correction screen, exactly like zoneResults() one
     * type over.
     *
     * @param array<array-key, string> $associations pair id => picked choice key
     *
     * @return array<string, bool> empty when the question is not an Apparier
     */
    public function matchingResults(QuizQuestionDefinition $question, array $associations): array
    {
        if (QuestionType::Apparier !== $question->getType()) {
            return [];
        }

        // An association is graded on what the picked choice *is* rather than on the key it was
        // picked under - see getMatchingSignatures() for why, and for why this one comparison
        // serves a text column and an image column alike.
        $signatures = $question->getMatchingSignatures();

        $results = [];
        foreach ($question->getMatchingPairIds() as $pairId) {
            $picked = $associations[$pairId] ?? null;
            $expected = $signatures[$pairId] ?? null;
            $results[$pairId] = null !== $picked && null !== $expected && ($signatures[$picked] ?? null) === $expected;
        }

        return $results;
    }

    /**
     * Per-zone correctness of a Légende question, keyed by zone id - drives the partial score and
     * the green/red rendering of each zone on the correction screen, exactly like blankResults()
     * for the blanks.
     *
     * @param array<array-key, string> $placements zone id => placed choice key
     *
     * @return array<string, bool> empty when the question is not a Légende
     */
    public function zoneResults(QuizQuestionDefinition $question, array $placements): array
    {
        if (QuestionType::Legende !== $question->getType()) {
            return [];
        }

        $results = [];
        foreach ($question->getZoneIds() as $zoneId) {
            // A label is right on its own zone and nowhere else - the choice key of a real label
            // IS its zone id, so this is a plain equality (distractors carry d0/d1/… keys and can
            // never match).
            $results[$zoneId] = ($placements[$zoneId] ?? null) === $zoneId;
        }

        return $results;
    }

    /**
     * Per-blank correctness in text order - drives the partial score of a real attempt and the
     * green/red rendering of each blank on the correction screen (1m).
     *
     * @param list<string> $responses
     *
     * @return list<bool> empty when the question has no typed answer at all - a réponse courte is
     *                     one entry, a texte à trous one per blank
     */
    public function blankResults(QuizQuestionDefinition $question, array $responses): array
    {
        if (!$question->getType()->usesBlankAnswers()) {
            return [];
        }

        $ignoreCase = $question->isIgnoreCase();
        $tolerateTypo = $question->isTolerateTypo();
        $results = [];

        foreach ($question->getBlankAnswers() as $index => $variants) {
            // A blank the teacher left without any accepted answer can never be got right - grading
            // it as correct would hand out free points for an unfinished question.
            $results[] = [] !== $variants
                && BlankTextParser::matches($responses[$index] ?? '', $variants, $ignoreCase, $tolerateTypo);
        }

        return $results;
    }

    /**
     * @param list<array{id: int, correct: bool, orderIndex: int}> $answers
     *
     * @return list<int>
     */
    public function correctAnswerIds(array $answers): array
    {
        return array_values(array_map(
            static fn (array $answer): int => $answer['id'],
            array_filter($answers, static fn (array $answer): bool => $answer['correct']),
        ));
    }

    /**
     * @param list<array{id: int, correct: bool, orderIndex: int}> $answers
     * @param list<int>                                            $selectedIds
     */
    private function isSingleCorrect(array $answers, array $selectedIds): bool
    {
        if (1 !== \count($selectedIds)) {
            return false;
        }

        $correctId = $this->correctAnswerIds($answers)[0] ?? null;

        return null !== $correctId && $selectedIds[0] === $correctId;
    }

    /**
     * @param list<array{id: int, correct: bool, orderIndex: int}> $answers
     * @param list<int>                                            $selectedIds
     */
    private function isMultiCorrect(array $answers, array $selectedIds): bool
    {
        $correctIds = $this->correctAnswerIds($answers);

        if ([] === $correctIds) {
            return false;
        }

        sort($selectedIds);
        sort($correctIds);

        return $selectedIds === $correctIds;
    }

    /**
     * The expected sequence is every answer in orderIndex order - an "ordre" question carries its
     * answer in that ordering, not in the `correct` flag (see QuizCsvImporter, which reorders the
     * options it read rather than flagging them).
     *
     * @param list<array{id: int, correct: bool, orderIndex: int}> $answers
     * @param list<int>                                            $selectedIds
     */
    private function isOrderCorrect(array $answers, array $selectedIds): bool
    {
        usort($answers, static fn (array $a, array $b): int => $a['orderIndex'] <=> $b['orderIndex']);

        return $selectedIds === array_map(static fn (array $answer): int => $answer['id'], $answers);
    }

    /** @param list<string> $responses */
    private function areBlanksCorrect(QuizQuestionDefinition $question, array $responses): bool
    {
        $results = $this->blankResults($question, $responses);

        return [] !== $results && !\in_array(false, $results, true);
    }

    /**
     * A Zone answer is the QcmMulti rule on zone ids: the clicked set must exactly equal the
     * correct set. A question whose correct list is empty (unfinished, or stale against a
     * rewritten support - see getZoneCorrectIds()) can never be right.
     *
     * @param array<array-key, string> $clickedIds
     */
    private function isZoneSelectionCorrect(QuizQuestionDefinition $question, array $clickedIds): bool
    {
        $correctIds = $question->getZoneCorrectIds();

        if ([] === $correctIds) {
            return false;
        }

        $clicked = array_values(array_unique(array_map(strval(...), $clickedIds)));
        sort($clicked);
        sort($correctIds);

        return $clicked === $correctIds;
    }

    /** @param array<array-key, string> $placements */
    private function areLegendePlacementsCorrect(QuizQuestionDefinition $question, array $placements): bool
    {
        $results = $this->zoneResults($question, $placements);

        return [] !== $results && !\in_array(false, $results, true);
    }

    /** @param array<array-key, string> $associations */
    private function areMatchingsCorrect(QuizQuestionDefinition $question, array $associations): bool
    {
        $results = $this->matchingResults($question, $associations);

        return [] !== $results && !\in_array(false, $results, true);
    }
}
