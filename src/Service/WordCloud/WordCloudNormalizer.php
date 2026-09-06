<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

/**
 * The aggregation key of a submitted word - what « Regrouper les variantes (casse, accents,
 * pluriels) » actually compares.
 *
 * The key is **never displayed**: WordCloudAggregator shows the most frequent raw spelling of a
 * group back. That is what makes the two lossy steps below safe, and both exist to merge rather
 * than to be pretty:
 *
 * - every run of non-alphanumeric characters becomes one space, so « pare-feu », « pare feu » and
 *   « pare_feu » are one key. Removing the separator instead would have merged those three with
 *   « parefeu » and nothing else, at the cost of « mot de passe » becoming « motdepasse » - which
 *   then no longer strips its plural token by token.
 * - a trailing `s` comes off any token of four characters or more. « virus » therefore keys on
 *   « viru », and « cours » on « cour ». Both are wrong as French and right as a key: every
 *   spelling of a word lands on the same one. The four-character floor is what stops « bus » and
 *   « les » from colliding with « bu » and « le », which would merge two genuinely different words.
 */
class WordCloudNormalizer
{
    /**
     * The shortest token that may lose its final `s`. Below it the risk of merging two unrelated
     * words outweighs the plural it would catch.
     */
    private const int PLURAL_MIN_LENGTH = 4;

    public function normalize(string $word): string
    {
        $lowered = mb_strtolower(trim($word));

        // Decompose, then drop the combining marks: « é » becomes « e » without a transliteration
        // table, and a character with no decomposition (a digit, a CJK glyph) passes through.
        $decomposed = \Normalizer::normalize($lowered, \Normalizer::FORM_D);
        if (false === $decomposed) {
            $decomposed = $lowered;
        }
        $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;

        $spaced = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $stripped) ?? $stripped;

        $tokens = [];
        foreach (preg_split('/\s+/u', trim($spaced), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $tokens[] = $this->singular($token);
        }

        return implode(' ', $tokens);
    }

    private function singular(string $token): string
    {
        if (mb_strlen($token) < self::PLURAL_MIN_LENGTH || !str_ends_with($token, 's')) {
            return $token;
        }

        return mb_substr($token, 0, -1);
    }
}
