<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestionDefinition;
use App\Enum\QuestionType;
use App\Util\BlankTextParser;

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
     * @param list<array{id: int, correct: bool, orderIndex: int}> $answers        the question's own answers, any order
     * @param list<int>                                            $selectedIds    in submission order (which only matters for "ordre")
     * @param list<string>                                         $blankResponses in text order
     * @param array<array-key, string>                             $zoneResponses  Zone: the clicked zone ids; Legende: zone id => placed choice key
     */
    public function isCorrect(QuizQuestionDefinition $question, array $answers, array $selectedIds, array $blankResponses = [], array $zoneResponses = []): bool
    {
        return match ($question->getType()) {
            QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image => $this->isSingleCorrect($answers, $selectedIds),
            QuestionType::QcmMulti => $this->isMultiCorrect($answers, $selectedIds),
            QuestionType::Ordre => $this->isOrderCorrect($answers, $selectedIds),
            QuestionType::TexteATrous => $this->areBlanksCorrect($question, $blankResponses),
            QuestionType::Zone => $this->isZoneSelectionCorrect($question, $zoneResponses),
            QuestionType::Legende => $this->areLegendePlacementsCorrect($question, $zoneResponses),
        };
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
     * @return list<bool> empty when the question is not a texte à trous, or has no blank at all
     */
    public function blankResults(QuizQuestionDefinition $question, array $responses): array
    {
        if (QuestionType::TexteATrous !== $question->getType()) {
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
}
