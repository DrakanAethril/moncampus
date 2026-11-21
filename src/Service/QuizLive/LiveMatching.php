<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use App\Entity\QuizQuestionDefinition;

/**
 * An Apparier question as the live room plays it (2026-11-21): the left column in the order the
 * teacher wrote it, each item under one of the room's six shapes - which is why a live appariement
 * holds six pairs at most -, and the right column the phones pick from.
 *
 * Two ways of answering, decided here and nowhere else:
 * - **pairs**: the right-hand items (decoys included) shuffled the same way for the whole room,
 *   each used once - a tap on the left item, a tap on its answer (Duolingo's « Associe les paires »);
 * - **sort**: when the right column only repeats a few values - a classement imported as Apparier,
 *   six animals into « mammifère / oiseau / reptile » - each left item is given one of those values
 *   by a button of its own, every value usable as often as needed.
 *
 * A phone never learns a pair id nor a choice key - the key of a real right-hand item *is* its pair's
 * id, so sending it would hand out the answer. It answers with positions: `matches[i]` is the
 * position, in the right column it was shown, of what it gave the i-th left item. associations()
 * turns that back into what App\Service\QuizAnswerChecker::matchingResults() grades, which compares
 * what the picked item *is* (getMatchingSignatures()): a repeated value is right under any copy.
 *
 * @phpstan-type LeftItem array{id: string, text: string, image: ?string, signature: string}
 * @phpstan-type RightItem array{key: string, text: string, image: ?string, signature: string}
 */
final readonly class LiveMatching
{
    /** One shape a left item: the room has six (QuizLiveSessionService::SHAPES, which it must equal). */
    public const int MAX_PAIRS = \App\Service\QuizLiveSessionService::SHAPE_COUNT;

    /** The most values the sort mode puts side by side on a phone's row. */
    public const int MAX_CATEGORIES = 4;

    public const string PAIRS = 'pairs';
    public const string SORT = 'sort';

    /**
     * @param list<LeftItem>  $left
     * @param list<RightItem> $right
     */
    private function __construct(
        public string $mode,
        public array $left,
        public array $right,
    ) {
    }

    /** Whether the room plays it: two to six pairs. */
    public static function isPlayable(QuizQuestionDefinition $question): bool
    {
        $count = \count($question->getMatchingPairs());

        return $count >= 2 && $count <= self::MAX_PAIRS;
    }

    /** @param int $seed the same for the whole room and for a reloaded screen */
    public static function of(QuizQuestionDefinition $question, int $seed): self
    {
        $signatures = $question->getMatchingSignatures();

        $left = [];
        foreach ($question->getMatchingPairs() as $pair) {
            $left[] = [
                'id' => $pair['id'],
                'text' => $pair['left'],
                'image' => $pair['leftImage'],
                'signature' => $signatures[$pair['id']] ?? '',
            ];
        }

        $choices = [];
        foreach ($question->getMatchingChoices() as $choice) {
            $choices[] = $choice + ['signature' => $signatures[$choice['key']] ?? ''];
        }

        $distinct = [];
        foreach ($choices as $choice) {
            $distinct[$choice['signature']] ??= $choice;
        }

        if (self::sorts(\count($left), \count($distinct), $question->getMatchingRightKind()->isImage())) {
            return new self(self::SORT, $left, array_values($distinct));
        }

        $order = LiveOrderShuffle::shown(array_keys($choices), $seed);

        return new self(self::PAIRS, $left, array_map(static fn (int $index): array => $choices[$index], $order));
    }

    /**
     * The sort mode: some right-hand value repeats, and there are few enough of them to sit as
     * buttons beside each left item. Pictures stay in pairs: a row of thumbnails is no button.
     */
    public static function sorts(int $pairs, int $distinctValues, bool $rightIsImage): bool
    {
        return !$rightIsImage && $distinctValues < $pairs && $distinctValues <= self::MAX_CATEGORIES;
    }

    /**
     * Every left item answered, with a position the right column has - each once, in pairs mode.
     *
     * @param list<int> $matches
     */
    public function isWellFormed(array $matches): bool
    {
        if (\count($matches) !== \count($this->left)) {
            return false;
        }
        foreach ($matches as $position) {
            if ($position < 0 || $position >= \count($this->right)) {
                return false;
            }
        }

        return self::SORT === $this->mode || \count(array_unique($matches)) === \count($matches);
    }

    /**
     * What the phone answered, as the grading reads it: pair id => choice key.
     *
     * @param list<int> $matches
     *
     * @return array<string, string>
     */
    public function associations(array $matches): array
    {
        $associations = [];
        foreach ($this->left as $index => $item) {
            $position = $matches[$index] ?? null;
            if (null !== $position && isset($this->right[$position])) {
                $associations[$item['id']] = $this->right[$position]['key'];
            }
        }

        return $associations;
    }

    /**
     * For each left item, the right-hand item it goes with - what the results show.
     *
     * @return list<RightItem|null>
     */
    public function expected(): array
    {
        $bySignature = [];
        foreach ($this->right as $item) {
            $bySignature[$item['signature']] ??= $item;
        }

        return array_map(static fn (array $item): ?array => $bySignature[$item['signature']] ?? null, $this->left);
    }
}
