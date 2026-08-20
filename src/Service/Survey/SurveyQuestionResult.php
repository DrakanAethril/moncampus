<?php

declare(strict_types=1);

namespace App\Service\Survey;

/**
 * What one question's answers add up to - the whole arithmetic of the results screen, on plain
 * numbers (design/validated/surveys.md §7.5 and §9).
 *
 * Deliberately built out of primitives rather than entities: this is one of the two places in the
 * feature where a mistake is **silent** - a wrong percentage raises nothing, it simply displays -
 * so it is written test-first and testable without a database.
 *
 * The three shapes, and the reason each is different:
 *
 *  - **Single choice** - percentages over the people who answered *that* question; they sum to
 *    100 %. With the scale flag, the answers' order_index becomes a value and an average appears
 *    beside the distribution, never in its place.
 *  - **Multiple choice** - the same denominator, but the sum goes above 100 %, and the screen has
 *    to say so or a reader concludes the arithmetic is broken.
 *  - **Ranking** - no percentage at all: an average rank reads, twelve position bars do not.
 *
 * A Commentaire enters no percentage and no comparison; it does enter the per-question response
 * rate, because a comment left blank is a skipped question like any other.
 */
final readonly class SurveyQuestionResult
{
    /**
     * @param array<int, int> $counts       answer id => how many people picked it
     * @param array<int, int> $orderIndexes answer id => its order_index, which on a scale is its value
     * @param array<int, int> $rankSums     answer id => the sum of the ranks it was given (Ordre only)
     */
    public function __construct(
        public int $questionId,
        public string $type,
        public string $label,
        public bool $isScale,
        /** How many people answered *this* question. */
        public int $answered,
        /** How many people the campaign aims at - the response rate's denominator. */
        public int $targeted,
        public array $counts = [],
        public array $orderIndexes = [],
        public array $rankSums = [],
    ) {
    }

    /** An intertitle is not a question: it never reaches this class. */
    public static function isMeasurable(string $type): bool
    {
        return 'titre' !== $type;
    }

    /** Only the two choice types are read as percentages. */
    public function hasPercentages(): bool
    {
        return \in_array($this->type, ['unique', 'multiple'], true);
    }

    /**
     * The share of the people who answered this question that picked this answer. On a multiple
     * choice the shares sum above 100 %, which is correct and said out loud on screen.
     */
    public function percentFor(int $answerId): float
    {
        if (0 === $this->answered) {
            return 0.0;
        }

        return ($this->counts[$answerId] ?? 0) * 100 / $this->answered;
    }

    public function countFor(int $answerId): int
    {
        return $this->counts[$answerId] ?? 0;
    }

    /** Whether the shares of this question sum above 100 % - true of a multiple choice that was answered. */
    public function sumsAboveOneHundred(): bool
    {
        if ('multiple' !== $this->type || 0 === $this->answered) {
            return false;
        }

        return array_sum($this->counts) > $this->answered;
    }

    /** How many of the people aimed at answered this question, as a percentage. */
    public function responseRate(): float
    {
        return 0 === $this->targeted ? 0.0 : $this->answered * 100 / $this->targeted;
    }

    public function skipped(): int
    {
        return max(0, $this->targeted - $this->answered);
    }

    /**
     * The single number two waves are compared on - « le niveau déclaré à l'entrée est de 1,00 sur
     * 4 ». Null unless the author declared their list to be a scale: averaging an arbitrary list of
     * tools would mean nothing.
     *
     * It is shown *beside* the distribution, never instead of it: an average over an ordinal scale
     * assumes every step is worth the same, which is debatable. It summarises and compares; the
     * distribution stays the data.
     */
    public function scaleAverage(): ?float
    {
        if (!$this->isScale || 'unique' !== $this->type || 0 === $this->answered) {
            return null;
        }

        $weighted = 0;
        foreach ($this->counts as $answerId => $count) {
            $weighted += $count * ($this->orderIndexes[$answerId] ?? 0);
        }

        return $weighted / $this->answered;
    }

    /** The top of the scale - « sur 4 » when there are five answers, 0 being the low pole. */
    public function scaleMax(): int
    {
        return [] === $this->orderIndexes ? 0 : max($this->orderIndexes);
    }

    /**
     * The average rank this item was given, 1 being first. Comes straight out of the aggregate's
     * AVG(order_index), shifted by one because the stored rank is 0-based.
     */
    public function averageRankFor(int $answerId): float
    {
        $count = $this->counts[$answerId] ?? 0;

        if (0 === $count) {
            return 0.0;
        }

        return ($this->rankSums[$answerId] ?? 0) / $count + 1;
    }

    /**
     * The collective ranking - the items ordered by average rank, the most urgent first.
     *
     * @return list<int>
     */
    public function rankedAnswerIds(): array
    {
        // array_keys already yields a list, and usort reindexes - no array_values() to add here.
        $ids = array_keys($this->counts);
        usort($ids, fn (int $a, int $b): int => $this->averageRankFor($a) <=> $this->averageRankFor($b));

        return $ids;
    }

    /**
     * Whether the distribution may be shown at all.
     *
     * On an anonymous campaign, nothing is shown under three responses: on a target of four people,
     * a two-bar histogram points at somebody (§7.6). The response rate itself is never hidden - it
     * says nothing about the content.
     */
    public function isDisclosable(bool $anonymous): bool
    {
        return !$anonymous || $this->answered >= SurveyResults::ANONYMOUS_DISCLOSURE_THRESHOLD;
    }
}
