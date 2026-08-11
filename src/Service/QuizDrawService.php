<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
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
     * The word bank of a texte à trous question in banque mode, always shuffled ("banque affichée
     * mélangée" - screen 2c): shown in definition order it would spell out the answers, since the
     * correct words come first and in text order (App\Entity\QuizQuestionDefinitionTrait::getWordBank()).
     *
     * Same seed rule as orderAnswers() - this is the same fairness question, one level down. Sorted
     * on the word itself rather than an id, as bank entries are plain strings with no row of their own.
     *
     * @return list<string> in this attempt's presentation order
     */
    public function orderWordBank(QuizInstanceQuestion $question, QuizAttempt $attempt): array
    {
        $instance = $attempt->getQuizInstance();
        $seed = $instance->isAnswerOrderPerStudent() ? $attempt->getShuffleSeed() : $instance->getId();
        $salt = 'bank-'.$question->getId();

        $words = $question->getWordBank();
        usort($words, static fn (string $a, string $b): int => md5($seed.$salt.$a) <=> md5($seed.$salt.$b));

        return $words;
    }

    /**
     * The labels a Légende question offers to place, always shuffled: in definition order the
     * first label would sit on the first zone, spelling out the solution - same problem and same
     * seed rule as orderWordBank(), one type over. Sorted on the choice key, which is stable
     * within a question.
     *
     * @return list<array{key: string, text: string}> in this attempt's presentation order
     */
    public function orderZoneChoices(QuizInstanceQuestion $question, QuizAttempt $attempt): array
    {
        $instance = $attempt->getQuizInstance();
        $seed = $instance->isAnswerOrderPerStudent() ? $attempt->getShuffleSeed() : $instance->getId();
        $salt = 'zone-choices-'.$question->getId();

        $choices = $question->getLegendeChoices();
        usort($choices, static fn (array $a, array $b): int => md5($seed.$salt.$a['key']) <=> md5($seed.$salt.$b['key']));

        return $choices;
    }

    /**
     * The right-hand items an Apparier question offers, always shuffled: in definition order the
     * first choice would sit opposite the first pair, spelling out the whole answer - same problem
     * and same seed rule as orderZoneChoices(), one type over.
     *
     * @return list<array{key: string, text: string, image: ?string}> in this attempt's presentation order
     */
    public function orderMatchingChoices(QuizInstanceQuestion $question, QuizAttempt $attempt): array
    {
        $instance = $attempt->getQuizInstance();
        $seed = $instance->isAnswerOrderPerStudent() ? $attempt->getShuffleSeed() : $instance->getId();
        $salt = 'matching-choices-'.$question->getId();

        $choices = $question->getMatchingChoices();
        usort($choices, static fn (array $a, array $b): int => md5($seed.$salt.$a['key']) <=> md5($seed.$salt.$b['key']));

        return $choices;
    }

    /**
     * The left-hand rows of an Apparier question, shuffled too. Unlike a légende - whose zones are
     * pinned to a support and cannot move - the left column is a free list, so leaving it in
     * definition order would leak the pairing to a student comparing two screens.
     *
     * @return list<array{id: string, left: string, right: string, leftImage: ?string, rightImage: ?string}> in this attempt's presentation order
     */
    public function orderMatchingPairs(QuizInstanceQuestion $question, QuizAttempt $attempt): array
    {
        $instance = $attempt->getQuizInstance();
        $seed = $instance->isAnswerOrderPerStudent() ? $attempt->getShuffleSeed() : $instance->getId();
        $salt = 'matching-pairs-'.$question->getId();

        $pairs = $question->getMatchingPairs();
        usort($pairs, static fn (array $a, array $b): int => md5($seed.$salt.$a['id']) <=> md5($seed.$salt.$b['id']));

        return $pairs;
    }

    /**
     * The values a "calculée" question asks *this* student about ("un train roule à {v} km/h").
     *
     * Always seeded on the attempt, never on the instance, and deliberately not behind the three
     * fairness toggles: the whole point of the type is that two students sitting side by side get
     * different numbers, and a practice attempt redrawn each time is what makes it worth redoing.
     *
     * Deterministic all the same, like every other draw here: the same attempt reloading the same
     * question must not be handed a new statement. The stored copy on the attempt answer is what
     * grading actually uses (App\Entity\QuizAttemptAnswer::$numericResponse) - this recomputes the
     * same values for the screen that asks the question.
     *
     * @return array<string, float>
     */
    public function drawNumericVariables(QuizInstanceQuestion $question, QuizAttempt $attempt): array
    {
        $seed = $attempt->getShuffleSeed();
        $values = [];

        foreach ($question->getNumericVariables() as $variable) {
            $salt = 'numeric-'.$question->getId().'-'.$variable['name'];
            // How many steps fit in the range, inclusive of both ends: a variable from 80 to 140 by
            // 10 has seven possible values, not six.
            $steps = (int) floor(($variable['max'] - $variable['min']) / $variable['step'] + 1e-9);
            $pick = $steps > 0 ? $this->deterministicIndex($seed, $salt, $steps + 1) : 0;

            $value = $variable['min'] + $pick * $variable['step'];
            // Rounded to the variable's own decimals: the statement shows this number, and floating
            // point makes 80 + 3 * 0.1 print as 80.30000000000001 otherwise.
            $values[$variable['name']] = round(min($value, $variable['max']), $variable['decimals']);
        }

        return $values;
    }

    /**
     * A stable pseudo-random integer in [0, $count) for this seed and salt - the same md5 trick the
     * sort comparators above use, read as a number rather than as an ordering.
     */
    private function deterministicIndex(int $seed, string $salt, int $count): int
    {
        // 8 hex digits is 32 bits, which is well inside PHP's int on every platform this runs on.
        $hash = (int) hexdec(substr(md5($seed.$salt), 0, 8));

        return $count > 0 ? $hash % $count : 0;
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
