<?php

declare(strict_types=1);

namespace App\Service\Survey;

/**
 * How the waves of a series line up - the arithmetic of the comparison, on primitives
 * (design/validated/surveys.md §7.1 and §7.15).
 *
 * Alignment is by comparison_key and by nothing else. Two waves are comparable because they belong
 * to the same series, and their questions match because a replay copies the snapshot word for word:
 * the keys are equal **by construction**. Guessing comparability by testing labels and targets
 * would give a fragile heuristic, and a student changing class would silently erase a comparison.
 *
 * So this class really exists for the one abnormal case - a still-draft wave edited before opening.
 * The changed question is then declared « modifiée entre les vagues — non comparable », greyed out
 * and out of the deltas, while every other question keeps aligning.
 */
final readonly class SurveyWaveAlignment
{
    /**
     * Beyond four waves the screen stops stacking bars and switches to an evolution curve. Written
     * here rather than discovered on screen: a fifth bar in a three-step ramp has no shade left.
     */
    public const int MAX_STACKED_WAVES = 4;

    /**
     * @param list<string>              $comparableKeys keys present, with the same shape, in every wave
     * @param list<string>              $incomparableKeys keys that exist but do not line up
     * @param array<int, list<string>>  $keysByWave     wave number => its keys, in order
     */
    private function __construct(
        private array $comparableKeys,
        private array $incomparableKeys,
        private array $keysByWave,
    ) {
    }

    /**
     * @param array<int, array<string, string>> $wavesByNumber wave number => (comparison key => type)
     */
    public static function align(array $wavesByNumber): self
    {
        $waveCount = \count($wavesByNumber);

        $keysByWave = [];
        $occurrences = [];
        foreach ($wavesByNumber as $waveNumber => $questions) {
            $keys = [];
            foreach ($questions as $key => $type) {
                // Only the types that carry proposed answers enter a comparison: two lists of
                // verbatims put side by side do not subtract, and an intertitle has nothing to
                // align. They are not "incomparable" either - they are simply out of it.
                if (!\in_array($type, ['unique', 'multiple', 'ordre'], true)) {
                    continue;
                }

                $keys[] = $key;
                $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            }
            $keysByWave[$waveNumber] = $keys;
        }

        $comparable = [];
        $incomparable = [];
        foreach ($occurrences as $key => $count) {
            // Present in every wave, or aligned with nothing.
            if ($waveCount > 1 && $count === $waveCount) {
                $comparable[] = $key;
            } else {
                $incomparable[] = $key;
            }
        }

        return new self($comparable, $incomparable, $keysByWave);
    }

    /** @return list<string> */
    public function comparableKeys(): array
    {
        return $this->comparableKeys;
    }

    /** @return list<string> */
    public function incomparableKeys(): array
    {
        return $this->incomparableKeys;
    }

    public function hasSomethingToCompare(): bool
    {
        return [] !== $this->comparableKeys;
    }

    /** @return array<int, list<string>> */
    public function keysByWave(): array
    {
        return $this->keysByWave;
    }

    /**
     * The movement between two waves, in points. Null on the first wave, which has nothing behind
     * it - « +8 pts » only means something against something.
     */
    public static function delta(?float $previous, float $current): ?float
    {
        return null === $previous ? null : $current - $previous;
    }

    public static function needsCurve(int $waveCount): bool
    {
        return $waveCount > self::MAX_STACKED_WAVES;
    }

    /**
     * Why the individual comparison can show nothing - null when it can.
     *
     * An anonymous wave refuses outright, and for an administrator too: there is no name stored to
     * put on a row. That is the branch which must never grow an isAdmin() exception.
     */
    public static function individualRefusal(bool $firstAnonymous, bool $secondAnonymous): ?SurveyComparisonRefusal
    {
        return $firstAnonymous || $secondAnonymous ? SurveyComparisonRefusal::AnonymousWave : null;
    }

    /**
     * The people present in the target of **both** waves, and only them: a student who arrived
     * mid-year has no September column, and a half-empty row reads as a regression.
     *
     * @param list<int> $firstTargetIds
     * @param list<int> $secondTargetIds
     *
     * @return list<int>
     */
    public static function sharedTarget(array $firstTargetIds, array $secondTargetIds): array
    {
        return array_values(array_intersect($firstTargetIds, $secondTargetIds));
    }
}
