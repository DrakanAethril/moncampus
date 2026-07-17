<?php

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Enum\QuestionDifficulty;
use App\Enum\QuizMode;

/**
 * Turns a QuizInstance's frozen question pool into one student's actual attempt: which N of the M
 * questions, in what order, with each question's answers in what order - see
 * design/design_campus_manager/README.md's "Générateur de quiz" section (the 3 "toggles
 * d'équité") and App\Entity\QuizAttempt's class docblock.
 *
 * No stored shuffled copy: every draw/order is deterministic given a seed, computed the same way
 * every time it's read (sort by md5(seed . salt . id)) - same convention as the QCM anti-cheat
 * design this reuses (see memory: "no stored shuffled copy... deterministic across reloads").
 * Which seed gets used is the actual fairness lever:
 * - $sameQuestionsForAll / not per-student order or answers -> QuizInstance::$id (same for every
 *   student/attempt against this instance).
 * - otherwise -> QuizAttempt::$shuffleSeed (unique per attempt).
 * Entraînement mode always uses the attempt's own seed for *selection* regardless of
 * $sameQuestionsForAll - "nouveau tirage à chaque tentative" means each practice attempt (even by
 * the same student) must differ, which an instance-wide deterministic seed could never produce.
 */
class QuizDrawService
{
    /** @return list<QuizInstanceQuestion> already in this attempt's presentation order */
    public function drawQuestions(QuizAttempt $attempt): array
    {
        $instance = $attempt->getQuizInstance();
        $pool = $instance->getQuestions()->toArray();

        $selectionSeed = (QuizMode::Entrainement === $instance->getMode() || !$instance->isSameQuestionsForAll())
            ? $attempt->getShuffleSeed()
            : $instance->getId();

        $byDifficulty = [
            'facile' => [],
            'moyen' => [],
            'difficile' => [],
        ];
        foreach ($pool as $question) {
            $byDifficulty[$question->getEffectiveDifficulty()->value][] = $question;
        }

        $wanted = [
            'facile' => $instance->getDifficultyFacileCount(),
            'moyen' => $instance->getDifficultyMoyenCount(),
            'difficile' => $instance->getDifficultyDifficileCount(),
        ];

        $selected = [];
        foreach ($wanted as $level => $count) {
            $selected = [...$selected, ...$this->pickDeterministic($byDifficulty[$level], $count, $selectionSeed, 'select-'.$level)];
        }

        // A difficulty level with fewer available questions than its recipe count leaves a
        // shortfall - fill it from whatever's left in the pool rather than under-drawing the
        // instance's configured question count.
        $shortfall = $instance->getQuestionCount() - \count($selected);
        if ($shortfall > 0) {
            $selectedIds = array_map(static fn (QuizInstanceQuestion $q): int => $q->getId(), $selected);
            $remaining = array_values(array_filter($pool, static fn (QuizInstanceQuestion $q): bool => !\in_array($q->getId(), $selectedIds, true)));
            $selected = [...$selected, ...$this->pickDeterministic($remaining, $shortfall, $selectionSeed, 'select-fallback')];
        }

        $orderSeed = $instance->isQuestionOrderPerStudent() ? $attempt->getShuffleSeed() : $instance->getId();

        return $this->sortDeterministic($selected, $orderSeed, 'order');
    }

    /** @return list<QuizInstanceAnswer> in this attempt's presentation order for $question */
    public function orderAnswers(QuizInstanceQuestion $question, QuizAttempt $attempt): array
    {
        $instance = $attempt->getQuizInstance();
        $seed = $instance->isAnswerOrderPerStudent() ? $attempt->getShuffleSeed() : $instance->getId();

        return $this->sortDeterministic($question->getAnswers()->toArray(), $seed, 'answer-'.$question->getId());
    }

    /**
     * @param list<QuizInstanceQuestion> $questions
     *
     * @return list<QuizInstanceQuestion>
     */
    private function pickDeterministic(array $questions, int $count, int $seed, string $salt): array
    {
        if ($count <= 0 || [] === $questions) {
            return [];
        }

        return \array_slice($this->sortDeterministic($questions, $seed, $salt), 0, min($count, \count($questions)));
    }

    /**
     * @template T of object
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function sortDeterministic(array $items, int $seed, string $salt): array
    {
        $items = [...$items];
        usort($items, static fn (object $a, object $b): int => md5($seed.$salt.$a->getId()) <=> md5($seed.$salt.$b->getId()));

        return $items;
    }
}
