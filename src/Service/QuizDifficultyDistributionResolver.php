<?php

namespace App\Service;

/**
 * Turns the 0-100 difficulty slider position (screen 1c) into a facile/moyen/difficile
 * percentage split, and that split into exact per-level question counts for a given draw size.
 * The percent→count step never trusts the client - it's recomputed here from the submitted
 * slider position at launch time (App\Service\QuizInstantiationService), then frozen onto the
 * QuizInstance, so the actual draw recipe is always server-authoritative.
 *
 * The 5 zones/presets below are a deliberate design choice (not derived from a formula): the
 * mockup's only worked example - slider at 70% ("Plutôt difficile") - resolves to 20/40/40,
 * which anchors the "Plutôt difficile" preset exactly.
 */
class QuizDifficultyDistributionResolver
{
    /** @return array{zoneLabelKey: string, facilePercent: int, moyenPercent: int, difficilePercent: int} */
    public function resolvePercents(int $sliderPosition): array
    {
        $position = max(0, min(100, $sliderPosition));

        return match (true) {
            $position <= 20 => ['zoneLabelKey' => 'quizDifficultyZoneTresFacileLabel', 'facilePercent' => 60, 'moyenPercent' => 30, 'difficilePercent' => 10],
            $position <= 40 => ['zoneLabelKey' => 'quizDifficultyZonePlutotFacileLabel', 'facilePercent' => 40, 'moyenPercent' => 40, 'difficilePercent' => 20],
            $position <= 60 => ['zoneLabelKey' => 'quizDifficultyZoneEquilibreLabel', 'facilePercent' => 20, 'moyenPercent' => 60, 'difficilePercent' => 20],
            $position <= 80 => ['zoneLabelKey' => 'quizDifficultyZonePlutotDifficileLabel', 'facilePercent' => 20, 'moyenPercent' => 40, 'difficilePercent' => 40],
            default => ['zoneLabelKey' => 'quizDifficultyZoneTresDifficileLabel', 'facilePercent' => 10, 'moyenPercent' => 30, 'difficilePercent' => 60],
        };
    }

    // Largest-remainder rounding - guarantees the three counts always sum to exactly
    // $totalQuestions (plain per-level rounding can over/undershoot by 1-2 questions).
    /** @return array{facile: int, moyen: int, difficile: int} */
    public function resolveCounts(int $facilePercent, int $moyenPercent, int $difficilePercent, int $totalQuestions): array
    {
        $raw = [
            'facile' => $facilePercent * $totalQuestions / 100,
            'moyen' => $moyenPercent * $totalQuestions / 100,
            'difficile' => $difficilePercent * $totalQuestions / 100,
        ];

        $counts = array_map(static fn (float $value): int => (int) floor($value), $raw);
        $remainder = $totalQuestions - array_sum($counts);

        $fractions = array_map(static fn (float $value): float => $value - floor($value), $raw);
        arsort($fractions);

        $keys = array_keys($fractions);
        for ($i = 0; $i < $remainder; ++$i) {
            ++$counts[$keys[$i % \count($keys)]];
        }

        return $counts;
    }
}
