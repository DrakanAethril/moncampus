<?php

declare(strict_types=1);

namespace App\SeriousGame\Latin;

/**
 * The Latin grammar Les Tablettes de Pompéi asks for: the five declensions of the noun, the two
 * classes of adjectives, and the pronouns a 4e-2nde student learns. Pure functions, read by the
 * template functions of the `latin` family - the lexicon itself (which words, what they mean) is in
 * the templates, where an administrator changes it with Claude; the rules of the grammar are here.
 *
 * - A **noun** is given as the dictionary gives it: `[nominative, genitive, gender]`, plus an
 *   optional meaning and an optional flag - `i` for an i-stem the rules would miss, `consonne` for
 *   the exceptions they would catch (canis, iuvenis). The declension is read from the genitive.
 * - An **adjective** is `[masculine, feminine, neuter, genitive]`: bonus, bona, bonum, boni;
 *   fortis, fortis, forte, fortis; ingens, ingens, ingens, ingentis.
 * - A **pronoun** is its lemma: is, hic, ille, qui, ego, tu, se.
 *
 * A form is keyed `case.number` (`acc.pl`), and `case.number.gender` for adjectives and pronouns.
 * The cases come in the French schools' order: nominatif, vocatif, accusatif, génitif, datif,
 * ablatif. Forms are written without macrons; a student may type them with or without
 * (same() folds them away, with the case and the letters j and v).
 */
final class LatinMorphology
{
    public const array CASES = ['nom', 'voc', 'acc', 'gen', 'dat', 'abl'];
    /** A pronoun's table: it has no vocative. */
    public const array CASES_WITHOUT_VOCATIVE = ['nom', 'acc', 'gen', 'dat', 'abl'];
    public const array NUMBERS = ['sg', 'pl'];
    public const array GENDERS = ['m', 'f', 'n'];

    private const array CASE_NAMES = ['nom' => 'nominatif', 'voc' => 'vocatif', 'acc' => 'accusatif', 'gen' => 'génitif', 'dat' => 'datif', 'abl' => 'ablatif'];
    private const array NUMBER_NAMES = ['sg' => 'singulier', 'pl' => 'pluriel'];
    private const array GENDER_NAMES = ['m' => 'masculin', 'f' => 'féminin', 'n' => 'neutre'];

    /** What a cell holds when the form does not exist (the vocative of a pronoun). */
    public const string NONE = '—';

    /**
     * The endings of each declension, in the order of CASES, singular then plural; `*` is the
     * nominative itself (the third declension's nominative is not built on its stem).
     */
    private const array ENDINGS = [
        '1' => [['a', 'a', 'am', 'ae', 'ae', 'a'], ['ae', 'ae', 'as', 'arum', 'is', 'is']],
        '2m' => [['us', 'e', 'um', 'i', 'o', 'o'], ['i', 'i', 'os', 'orum', 'is', 'is']],
        '2n' => [['um', 'um', 'um', 'i', 'o', 'o'], ['a', 'a', 'a', 'orum', 'is', 'is']],
        '3m' => [['*', '*', 'em', 'is', 'i', 'e'], ['es', 'es', 'es', 'um', 'ibus', 'ibus']],
        '3n' => [['*', '*', '*', 'is', 'i', 'e'], ['a', 'a', 'a', 'um', 'ibus', 'ibus']],
        '4m' => [['us', 'us', 'um', 'us', 'ui', 'u'], ['us', 'us', 'us', 'uum', 'ibus', 'ibus']],
        '4n' => [['u', 'u', 'u', 'us', 'u', 'u'], ['ua', 'ua', 'ua', 'uum', 'ibus', 'ibus']],
        '5' => [['es', 'es', 'em', 'ei', 'ei', 'e'], ['es', 'es', 'es', 'erum', 'ebus', 'ebus']],
    ];

    /**
     * The pronouns, each gender's forms in the order of CASES, singular then plural; a cell may
     * hold alternatives (`ei|ii`), the first one being the form shown.
     */
    private const array PRONOUNS = [
        'is' => [
            'm' => [['is', '', 'eum', 'eius', 'ei', 'eo'], ['ei|ii', '', 'eos', 'eorum', 'eis|iis', 'eis|iis']],
            'f' => [['ea', '', 'eam', 'eius', 'ei', 'ea'], ['eae', '', 'eas', 'earum', 'eis|iis', 'eis|iis']],
            'n' => [['id', '', 'id', 'eius', 'ei', 'eo'], ['ea', '', 'ea', 'eorum', 'eis|iis', 'eis|iis']],
        ],
        'hic' => [
            'm' => [['hic', '', 'hunc', 'huius', 'huic', 'hoc'], ['hi', '', 'hos', 'horum', 'his', 'his']],
            'f' => [['haec', '', 'hanc', 'huius', 'huic', 'hac'], ['hae', '', 'has', 'harum', 'his', 'his']],
            'n' => [['hoc', '', 'hoc', 'huius', 'huic', 'hoc'], ['haec', '', 'haec', 'horum', 'his', 'his']],
        ],
        'ille' => [
            'm' => [['ille', '', 'illum', 'illius', 'illi', 'illo'], ['illi', '', 'illos', 'illorum', 'illis', 'illis']],
            'f' => [['illa', '', 'illam', 'illius', 'illi', 'illa'], ['illae', '', 'illas', 'illarum', 'illis', 'illis']],
            'n' => [['illud', '', 'illud', 'illius', 'illi', 'illo'], ['illa', '', 'illa', 'illorum', 'illis', 'illis']],
        ],
        'qui' => [
            'm' => [['qui', '', 'quem', 'cuius', 'cui', 'quo'], ['qui', '', 'quos', 'quorum', 'quibus', 'quibus']],
            'f' => [['quae', '', 'quam', 'cuius', 'cui', 'qua'], ['quae', '', 'quas', 'quarum', 'quibus', 'quibus']],
            'n' => [['quod', '', 'quod', 'cuius', 'cui', 'quo'], ['quae', '', 'quae', 'quorum', 'quibus', 'quibus']],
        ],
        'ego' => ['*' => [['ego', '', 'me', 'mei', 'mihi', 'me'], ['nos', '', 'nos', 'nostri|nostrum', 'nobis', 'nobis']]],
        'tu' => ['*' => [['tu', 'tu', 'te', 'tui', 'tibi', 'te'], ['vos', 'vos', 'vos', 'vestri|vestrum', 'vobis', 'vobis']]],
        'se' => ['*' => [['', '', 'se', 'sui', 'sibi', 'se'], ['', '', 'se', 'sui', 'sibi', 'se']]],
    ];

    // ---------------------------------------------------------------- nouns

    /**
     * @param list<mixed> $entry
     */
    public static function declension(array $entry): int
    {
        $gen = self::genitive($entry);

        return match (true) {
            str_ends_with($gen, 'ae') => 1,
            str_ends_with($gen, 'ei') => 5,
            str_ends_with($gen, 'is') => 3,
            str_ends_with($gen, 'us') => 4,
            str_ends_with($gen, 'i') => 2,
            default => throw new \InvalidArgumentException('génitif illisible « '.$gen.' »'),
        };
    }

    /**
     * Every accepted form of a key - the first is the one shown.
     *
     * @param list<mixed> $entry
     *
     * @return list<string>
     */
    public static function forms(array $entry, string $key): array
    {
        [$case, $number] = self::key($key);
        $nom = self::nominative($entry);
        $gen = self::genitive($entry);
        $gender = self::gender($entry);
        $declension = self::declension($entry);
        $neuter = 'n' === $gender;
        $i = array_search($case, self::CASES, true);
        $n = 'sg' === $number ? 0 : 1;

        switch ($declension) {
            case 1:
                return [substr($gen, 0, -2).self::ENDINGS['1'][$n][$i]];
            case 2:
                $stem = substr($gen, 0, -1);
                if ($neuter) {
                    return [$stem.self::ENDINGS['2n'][$n][$i]];
                }
                if (0 === $n && ('nom' === $case || 'voc' === $case) && !str_ends_with($nom, 'us')) {
                    return [$nom]; // puer, ager: the nominative is the vocative
                }
                if (0 === $n && 'voc' === $case && str_ends_with($nom, 'ius')) {
                    return [substr($nom, 0, -2)]; // fili
                }
                if (0 === $n && 'nom' === $case) {
                    return [$nom];
                }

                return [$stem.self::ENDINGS['2m'][$n][$i]];
            case 3:
                $stem = substr($gen, 0, -2);
                $iStem = self::isIStem($entry);
                $pureNeuter = $neuter && $iStem;
                $ending = self::ENDINGS[$neuter ? '3n' : '3m'][$n][$i];
                if ('*' === $ending) {
                    return [$nom];
                }
                if ($pureNeuter && 0 === $n && 'abl' === $case) {
                    return [$stem.'i'];
                }
                if ($iStem && 1 === $n && 'gen' === $case) {
                    return [$stem.'ium'];
                }
                if ($pureNeuter && 1 === $n && \in_array($case, ['nom', 'voc', 'acc'], true)) {
                    return [$stem.'ia'];
                }
                if ($iStem && !$neuter && 1 === $n && 'acc' === $case) {
                    return [$stem.'es', $stem.'is'];
                }

                return [$stem.$ending];
            case 4:
                $stem = substr($gen, 0, -2);
                if ($neuter && 0 === $n && 'dat' === $case) {
                    return [$stem.'u', $stem.'ui'];
                }

                return [$stem.self::ENDINGS[$neuter ? '4n' : '4m'][$n][$i]];
            default:
                return [substr($gen, 0, -2).self::ENDINGS['5'][$n][$i]];
        }
    }

    /** @param list<mixed> $entry */
    public static function form(array $entry, string $key): string
    {
        return self::forms($entry, $key)[0];
    }

    /**
     * The six forms of a number, in the order of CASES.
     *
     * @param list<mixed> $entry
     *
     * @return list<string>
     */
    public static function paradigm(array $entry, string $number): array
    {
        return array_map(static fn (string $case): string => self::form($entry, $case.'.'.$number), self::CASES);
    }

    /**
     * The keys whose forms include this one: « rosae » is a genitive and a dative singular, a
     * nominative and a vocative plural.
     *
     * @param list<mixed> $entry
     *
     * @return list<string>
     */
    public static function analyses(array $entry, string $form): array
    {
        $out = [];
        foreach (self::NUMBERS as $number) {
            foreach (self::CASES as $case) {
                foreach (self::forms($entry, $case.'.'.$number) as $candidate) {
                    if (self::same($candidate, $form)) {
                        $out[] = $case.'.'.$number;
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * A form built with another declension's endings - what a student who mistakes the declension
     * writes. On the genitive's stem by default (« rosum »), or on the nominative's (« corpi », the
     * student who took corpus for a second-declension noun in -us).
     *
     * @param list<mixed> $entry
     */
    public static function formAs(array $entry, int $declension, string $key, string $from = 'genitive'): string
    {
        [$case, $number] = self::key($key);
        $neuter = 'n' === self::gender($entry);
        $table = match ($declension) {
            1 => '1',
            2 => $neuter ? '2n' : '2m',
            3 => $neuter ? '3n' : '3m',
            4 => $neuter ? '4n' : '4m',
            5 => '5',
            default => throw new \InvalidArgumentException('déclinaison inconnue '.$declension),
        };
        $ending = self::ENDINGS[$table][('sg' === $number) ? 0 : 1][(int) array_search($case, self::CASES, true)];
        $stem = 'nominative' === $from ? self::nominativeStem(self::nominative($entry)) : self::stem($entry);

        return '*' === $ending ? self::nominative($entry) : $stem.$ending;
    }

    /**
     * The stem the cases are built on: the genitive without its ending.
     *
     * @param list<mixed> $entry
     */
    public static function stem(array $entry): string
    {
        $gen = self::genitive($entry);

        return match (self::declension($entry)) {
            1, 3, 4, 5 => substr($gen, 0, -2),
            default => substr($gen, 0, -1),
        };
    }

    /** @param list<mixed> $entry */
    public static function isIStem(array $entry): bool
    {
        $flag = \is_string($entry[4] ?? null) ? $entry[4] : '';
        if ('i' === $flag) {
            return true;
        }
        if ('consonne' === $flag || 3 !== self::declension($entry)) {
            return false;
        }

        $nom = self::nominative($entry);
        $gen = self::genitive($entry);
        if ('n' === self::gender($entry)) {
            return (bool) preg_match('/(e|al|ar)$/', $nom);
        }

        // parisyllabic in -is / -es: civis, civis; nubes, nubis
        if (preg_match('/(is|es)$/', $nom) && self::syllables($nom) === self::syllables($gen)) {
            return true;
        }

        // a monosyllable whose stem ends in two consonants: urbs, mons, nox (noct-)
        return 1 === self::syllables($nom) && (bool) preg_match('/[^aeiouy][^aeiouy]$/', substr($gen, 0, -2));
    }

    /**
     * The analyses a student reads into a form by taking its ending for another declension's:
     * « rosis » read as a genitive singular, the ending -is of the third declension. Longest
     * endings first; never one of the form's true analyses.
     *
     * @param list<mixed> $entry
     *
     * @return list<string>
     */
    public static function misreadings(array $entry, string $form): array
    {
        $own = match (self::declension($entry)) {
            1 => ['1'],
            2 => ['2m', '2n'],
            3 => ['3m', '3n'],
            4 => ['4m', '4n'],
            default => ['5'],
        };
        $folded = self::fold($form);
        $found = [];
        foreach (self::ENDINGS as $table => $numbers) {
            if (\in_array((string) $table, $own, true)) {
                continue;
            }
            foreach ($numbers as $n => $endings) {
                foreach ($endings as $i => $ending) {
                    if ('*' !== $ending && \strlen($ending) >= 2 && str_ends_with($folded, $ending)) {
                        $found[self::CASES[$i].'.'.self::NUMBERS[$n]] = max($found[self::CASES[$i].'.'.self::NUMBERS[$n]] ?? 0, \strlen($ending));
                    }
                }
            }
        }
        arsort($found);

        return array_values(array_diff(array_keys($found), self::analyses($entry, $form)));
    }

    /**
     * The declension a student guesses from the nominative alone - corpus « looks like » dominus.
     */
    public static function declensionFromNominative(string $nominative): int
    {
        $nom = self::fold($nominative);

        return match (true) {
            str_ends_with($nom, 'a') => 1,
            str_ends_with($nom, 'us'), str_ends_with($nom, 'um'), str_ends_with($nom, 'er') => 2,
            str_ends_with($nom, 'u') => 4,
            str_ends_with($nom, 'es') => 5,
            default => 3,
        };
    }

    /**
     * The case a student most often puts in the place of another one: the nominative for the
     * accusative and back, the datives and ablatives that share their plural, the genitive and
     * the dative that share the first declension's singular.
     */
    public static function confusedCase(string $case): string
    {
        return ['nom' => 'acc', 'voc' => 'nom', 'acc' => 'abl', 'gen' => 'dat', 'dat' => 'abl', 'abl' => 'dat'][$case] ?? $case;
    }

    /**
     * The form's ending: what follows the stem (« am » for rosam), empty when the form is not built
     * on the stem (a third-declension nominative).
     *
     * @param list<mixed> $entry
     */
    public static function ending(array $entry, string $key): string
    {
        $form = self::form($entry, $key);
        $stem = self::stem($entry);

        return str_starts_with($form, $stem) ? substr($form, \strlen($stem)) : '';
    }

    /** « à l’accusatif pluriel », « au génitif singulier ». */
    public static function atCase(string $key): string
    {
        $label = self::caseLabel($key);

        return (preg_match('/^[aeiou]/', $label) ? 'à l’' : 'au ').$label;
    }

    // ---------------------------------------------------------------- adjectives

    /**
     * @param list<mixed> $entry [masculine, feminine, neuter, genitive]
     *
     * @return list<string>
     */
    public static function adjectiveForms(array $entry, string $key): array
    {
        [$case, $number, $gender] = self::key($key, true);
        [$m, $f, $n, $gen] = array_map(static fn (mixed $x): string => \is_string($x) ? strtolower($x) : '', array_pad($entry, 4, ''));

        if (str_ends_with($gen, 'i') && !str_ends_with($gen, 'is')) {
            // first class: bonus, bona, bonum - the second and first declensions
            $stem = substr($gen, 0, -1);

            return match ($gender) {
                'm' => self::forms([$m, $gen, 'm'], $case.'.'.$number),
                'f' => self::forms([$f, $stem.'ae', 'f'], $case.'.'.$number),
                default => self::forms([$n, $gen, 'n'], $case.'.'.$number),
            };
        }

        // second class: the third declension's i-stems, with an ablative in -i for every gender
        $stem = substr($gen, 0, -2);
        $i = (int) array_search($case, self::CASES, true);
        if ('sg' === $number) {
            $nominative = match ($gender) {
                'm' => $m,
                'f' => $f,
                default => $n,
            };
            $forms = 'n' === $gender
                ? [$n, $n, $n, $stem.'is', $stem.'i', $stem.'i']
                : [$nominative, $nominative, $stem.'em', $stem.'is', $stem.'i', $stem.'i'];

            return [$forms[$i]];
        }
        if ('n' === $gender) {
            return [[$stem.'ia', $stem.'ia', $stem.'ia', $stem.'ium', $stem.'ibus', $stem.'ibus'][$i]];
        }
        if ('acc' === $case) {
            return [$stem.'es', $stem.'is'];
        }

        return [[$stem.'es', $stem.'es', $stem.'es', $stem.'ium', $stem.'ibus', $stem.'ibus'][$i]];
    }

    /**
     * @param list<mixed> $entry
     */
    public static function adjectiveStem(array $entry): string
    {
        $gen = \is_string($entry[3] ?? null) ? strtolower($entry[3]) : throw new \InvalidArgumentException('génitif de l’adjectif attendu');

        return str_ends_with($gen, 'is') ? substr($gen, 0, -2) : substr($gen, 0, -1);
    }

    /** @param list<mixed> $entry */
    public static function adjectiveForm(array $entry, string $key): string
    {
        return self::adjectiveForms($entry, $key)[0];
    }

    /**
     * @param list<mixed> $entry
     *
     * @return list<string>
     */
    public static function adjectiveParadigm(array $entry, string $gender, string $number): array
    {
        return array_map(static fn (string $case): string => self::adjectiveForm($entry, $case.'.'.$number.'.'.$gender), self::CASES);
    }

    /**
     * @param list<mixed> $entry
     *
     * @return list<string> every `case.number.gender` key whose forms include this one
     */
    public static function adjectiveAnalyses(array $entry, string $form): array
    {
        $out = [];
        foreach (self::NUMBERS as $number) {
            foreach (self::CASES as $case) {
                foreach (self::GENDERS as $gender) {
                    foreach (self::adjectiveForms($entry, $case.'.'.$number.'.'.$gender) as $candidate) {
                        if (self::same($candidate, $form)) {
                            $out[] = $case.'.'.$number.'.'.$gender;
                            break;
                        }
                    }
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- pronouns

    /**
     * @return list<string> the accepted forms; none for a form that does not exist (a pronoun's vocative)
     */
    public static function pronounForms(string $lemma, string $gender, string $key): array
    {
        [$case, $number] = self::key($key);
        $table = self::PRONOUNS[strtolower($lemma)] ?? throw new \InvalidArgumentException('pronom inconnu « '.$lemma.' »');
        $forms = $table[$gender] ?? $table['*'] ?? throw new \InvalidArgumentException('genre inconnu « '.$gender.' »');
        $cell = $forms['sg' === $number ? 0 : 1][(int) array_search($case, self::CASES, true)];

        return '' === $cell ? [] : explode('|', $cell);
    }

    public static function pronounForm(string $lemma, string $gender, string $key): string
    {
        return self::pronounForms($lemma, $gender, $key)[0] ?? self::NONE;
    }

    /** @return list<string> */
    public static function pronounParadigm(string $lemma, string $gender, string $number): array
    {
        return array_map(static fn (string $case): string => self::pronounForm($lemma, $gender, $case.'.'.$number), self::CASES);
    }

    /** Whether a pronoun is personal (ego, tu, se): it has no gender. */
    public static function isPersonal(string $lemma): bool
    {
        return isset(self::PRONOUNS[strtolower($lemma)]['*']);
    }

    /**
     * @return list<string> every `case.number.gender` key whose forms include this one - and
     *                      `case.number` for a personal pronoun, which has no gender
     */
    public static function pronounAnalyses(string $lemma, string $form): array
    {
        $personal = self::isPersonal($lemma);
        $out = [];
        foreach (self::NUMBERS as $number) {
            foreach (self::CASES as $case) {
                foreach ($personal ? ['*'] : self::GENDERS as $gender) {
                    foreach (self::pronounForms($lemma, $gender, $case.'.'.$number) as $candidate) {
                        if (self::same($candidate, $form)) {
                            $out[] = $case.'.'.$number.($personal ? '' : '.'.$gender);
                            break;
                        }
                    }
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- writing and reading

    /** A Latin text as compared: lower case, without macrons or breves, i for j, u for v. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $text = (string) preg_replace('/\p{Mn}+/u', '', false === $decomposed ? $text : $decomposed);
        $text = strtr($text, ['j' => 'i', 'v' => 'u']);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    public static function same(string $a, string $b): bool
    {
        return self::fold($a) === self::fold($b);
    }

    /** « accusatif pluriel », « génitif singulier féminin ». */
    public static function caseLabel(string $key): string
    {
        $parts = explode('.', $key);
        $words = [self::CASE_NAMES[$parts[0]] ?? $parts[0]];
        if (isset($parts[1])) {
            $words[] = self::NUMBER_NAMES[$parts[1]] ?? $parts[1];
        }
        if (isset($parts[2])) {
            $words[] = self::GENDER_NAMES[$parts[2]] ?? $parts[2];
        }

        return implode(' ', $words);
    }

    public static function caseName(string $case): string
    {
        return self::CASE_NAMES[$case] ?? $case;
    }

    public static function numberName(string $number): string
    {
        return self::NUMBER_NAMES[$number] ?? $number;
    }

    public static function genderName(string $gender): string
    {
        return self::GENDER_NAMES[$gender] ?? $gender;
    }

    public static function declensionName(int $declension): string
    {
        return (1 === $declension ? '1re' : $declension.'e').' déclinaison';
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function key(string $key, bool $withGender = false): array
    {
        $parts = explode('.', $key);
        if (!\in_array($parts[0], self::CASES, true) || !\in_array($parts[1] ?? '', self::NUMBERS, true)) {
            throw new \InvalidArgumentException('cas illisible « '.$key.' » (attendu : nom.sg, acc.pl…)');
        }
        $gender = $parts[2] ?? '';
        if ($withGender && !\in_array($gender, self::GENDERS, true)) {
            throw new \InvalidArgumentException('genre attendu dans « '.$key.' » (acc.sg.f…)');
        }

        return [$parts[0], $parts[1], $gender];
    }

    /** @param list<mixed> $entry */
    private static function nominative(array $entry): string
    {
        return \is_string($entry[0] ?? null) && '' !== $entry[0] ? strtolower($entry[0]) : throw new \InvalidArgumentException('nominatif attendu');
    }

    /** @param list<mixed> $entry */
    private static function genitive(array $entry): string
    {
        return \is_string($entry[1] ?? null) && '' !== $entry[1] ? strtolower($entry[1]) : throw new \InvalidArgumentException('génitif attendu');
    }

    /** @param list<mixed> $entry */
    private static function gender(array $entry): string
    {
        $gender = $entry[2] ?? null;

        return \is_string($gender) && \in_array($gender, self::GENDERS, true) ? $gender : throw new \InvalidArgumentException('genre attendu (m, f ou n)');
    }

    /** The nominative without its usual ending - the stem a student wrongly builds on. */
    private static function nominativeStem(string $nom): string
    {
        foreach (['us', 'um', 'is', 'es', 'a', 'u', 'x', 's'] as $ending) {
            if (str_ends_with($nom, $ending) && \strlen($nom) > \strlen($ending) + 1) {
                return substr($nom, 0, -\strlen($ending));
            }
        }

        return $nom;
    }

    private static function syllables(string $word): int
    {
        return preg_match_all('/(ae|au|oe|[aeiouy])/', $word);
    }
}
