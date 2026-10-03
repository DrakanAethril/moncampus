<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Enum\QuestionType;

/**
 * Everything the passation's take partials (templates/program/_quiz_*_take.html.twig) expect of a
 * **library** question, shuffled where the stored order would spell out the answer.
 *
 * Two screens ask a library question outside a class quiz - a marker inside a video
 * (App\Controller\StudentVideoCueController) and a validation quiz of a learning path
 * (App\Controller\LearningPath\PathQuizController) - and both draw it with
 * templates/quiz/_question_take_body.html.twig. This is the one place that gathers what that
 * partial reads, so the two cannot come to shuffle differently.
 */
final class QuizQuestionTakeView
{
    /**
     * @param array<string, float> $numericVariables the values a calculée asks this person for
     * @param bool                 $shuffleAnswers   every type's answer rows in a random order - a
     *                                               course's test asks for it, so that a reader
     *                                               coming back cannot answer by position. « Vrai »
     *                                               and « Faux » keep their order: swapping them
     *                                               hides nothing and only confuses
     *
     * @return array<string, mixed>
     */
    public static function of(QuizQuestion $question, array $numericVariables, bool $shuffleAnswers = false): array
    {
        $answers = $question->getAnswers()->toArray();
        // An "ordre" is answered by rearranging: handing the rows in their stored order would be
        // handing the answer.
        if (QuestionType::Ordre === $question->getType() || ($shuffleAnswers && QuestionType::VraiFaux !== $question->getType())) {
            shuffle($answers);
        }

        $wordBank = $question->getWordBank();
        shuffle($wordBank);
        $zoneChoices = $question->getLegendeChoices();
        shuffle($zoneChoices);
        $matchingChoices = $question->getMatchingChoices();
        shuffle($matchingChoices);
        $matchingPairs = $question->getMatchingPairs();
        shuffle($matchingPairs);

        return [
            'question' => $question,
            'answers' => $answers,
            'wordBank' => $wordBank,
            'zoneChoices' => $zoneChoices,
            'matchingChoices' => $matchingChoices,
            'matchingPairs' => $matchingPairs,
            'numericVariables' => $numericVariables,
        ];
    }
}
