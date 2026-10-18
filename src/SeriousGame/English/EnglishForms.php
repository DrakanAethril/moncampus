<?php

declare(strict_types=1);

namespace App\SeriousGame\English;

/**
 * The spelling rules of English verb endings The Irregulars' templates need beside their lexicon:
 * the third person of the simple present and the -ing form. Irregular forms (was, went, gone) are
 * never computed: they are written in the templates' lexicon, which a teacher reviews.
 */
final class EnglishForms
{
    /** Verbs whose last consonant doubles before -ing (one stressed syllable consonant-vowel-consonant). */
    private const array DOUBLING = ['stop', 'run', 'sit', 'rob', 'drop', 'grab', 'swim', 'get', 'put', 'begin', 'forget', 'travel', 'plan', 'shop', 'hit', 'cut'];

    /** He / she / it + present simple: watches, carries, plays, has, goes. */
    public static function thirdPerson(string $base): string
    {
        return match (true) {
            'have' === $base => 'has',
            'be' === $base => 'is',
            1 === preg_match('/(s|sh|ch|x|z|o)$/', $base) => $base.'es',
            1 === preg_match('/[^aeiou]y$/', $base) => substr($base, 0, -1).'ies',
            default => $base.'s',
        };
    }

    /** The -ing form: lying, writing, stopping, seeing, playing. */
    public static function ing(string $base): string
    {
        return match (true) {
            'be' === $base => 'being',
            str_ends_with($base, 'ie') => substr($base, 0, -2).'ying',
            \in_array($base, self::DOUBLING, true) => $base.substr($base, -1).'ing',
            1 === preg_match('/[^eoy]e$/', $base) => substr($base, 0, -1).'ing',
            default => $base.'ing',
        };
    }
}
