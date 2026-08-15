<?php

declare(strict_types=1);

namespace App\Referential;

/**
 * Decides which existing row a catalogue entry belongs to, on primitives only - no entity, no
 * database - so the rule can be pinned by App\Tests\Referential\ReferentialLabelMatcherTest.
 *
 * Everything it does is deliberately blunt. Comparison is exact once case, accents, apostrophes,
 * spacing and trailing punctuation are out of the way; anything looser (stemming, plural folding,
 * edit distance) would eventually attach one competency's referential content to another, which is
 * a silent, hard-to-notice mistake. Where a label genuinely diverges the catalogue declares the
 * variant by hand.
 */
class ReferentialLabelMatcher
{
    public function normalize(string $value): string
    {
        // Both apostrophes disappear rather than being unified: the referential and the database
        // disagree on which one they use, and neither spelling carries meaning.
        $value = str_replace(["\u{2019}", "'", "\u{02BC}"], '', $value);

        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        if (false === $transliterated) {
            $transliterated = mb_strtolower($value);
        }

        $collapsed = preg_replace('/[^a-z0-9]+/', ' ', $transliterated) ?? $transliterated;

        return trim($collapsed);
    }

    public function matches(string $left, string $right): bool
    {
        return $this->normalize($left) === $this->normalize($right);
    }

    /**
     * The key of the single candidate matching $label or one of $aliases, or null when none does -
     * and also null when several do, an ambiguity the caller must report rather than resolve.
     *
     * @param array<int, string> $candidates key => label
     * @param list<string>       $aliases    other spellings that identify the same entry
     */
    public function findKey(array $candidates, string $label, array $aliases): ?int
    {
        $wanted = [$this->normalize($label)];
        foreach ($aliases as $alias) {
            $wanted[] = $this->normalize($alias);
        }

        foreach ($wanted as $needle) {
            $found = [];
            foreach ($candidates as $key => $candidate) {
                if ($this->normalize($candidate) === $needle) {
                    $found[] = $key;
                }
            }

            if (1 === \count($found)) {
                return $found[0];
            }

            if (\count($found) > 1) {
                return null;
            }
        }

        return null;
    }

    /**
     * The key of the single teacher written "F. Sautour" in the referential, or null.
     *
     * Both the surname AND the first name's initial must agree, and the answer must be unique.
     * This referential names three different Sautour: matching on the surname alone would credit
     * one teacher with another's competencies.
     *
     * @param array<int, array{firstname: string, lastname: string}> $teachers
     */
    public function findTeacherKey(array $teachers, string $written): ?int
    {
        if (1 !== preg_match('/^\s*(\p{L})\S*\s*\.?\s*(.+?)\s*$/u', $written, $matches)) {
            return null;
        }

        $initial = $this->normalize($matches[1]);
        $surname = $this->normalize($matches[2]);

        if ('' === $initial || '' === $surname) {
            return null;
        }

        $found = [];
        foreach ($teachers as $key => $teacher) {
            $candidateInitial = mb_substr($this->normalize($teacher['firstname']), 0, 1);

            if ($candidateInitial === $initial && $this->normalize($teacher['lastname']) === $surname) {
                $found[] = $key;
            }
        }

        return 1 === \count($found) ? $found[0] : null;
    }
}
