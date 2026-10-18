<?php

declare(strict_types=1);

namespace App\SeriousGame\Latin;

/**
 * The declension tables a correction shows (Les Tablettes de Pompéi): the paradigm of the word the
 * question was about, the cells it asked for in bold. The restricted HTML of a statement - a
 * `table.sgm-t`, `th`, `td`, `i`, `b` - which the statement sanitizer lets through.
 */
final class LatinTables
{
    /**
     * Six cases down, singular and plural across.
     *
     * @param list<mixed>  $entry
     * @param list<string> $highlight keys (`acc.pl`)
     */
    public static function noun(array $entry, array $highlight = []): string
    {
        $rows = [['', 'Singulier', 'Pluriel']];
        foreach (LatinMorphology::CASES as $case) {
            $row = [ucfirst(LatinMorphology::caseName($case))];
            foreach (LatinMorphology::NUMBERS as $number) {
                $row[] = self::cell(LatinMorphology::form($entry, $case.'.'.$number), \in_array($case.'.'.$number, $highlight, true));
            }
            $rows[] = $row;
        }

        return self::html($rows);
    }

    /**
     * Six cases down, the three genders across, for one number.
     *
     * @param list<mixed>  $entry
     * @param list<string> $highlight keys (`acc.sg.f`)
     */
    public static function adjective(array $entry, string $number, array $highlight = []): string
    {
        $rows = [['', 'Masculin', 'Féminin', 'Neutre']];
        foreach (LatinMorphology::CASES as $case) {
            $row = [ucfirst(LatinMorphology::caseName($case))];
            foreach (LatinMorphology::GENDERS as $gender) {
                $key = $case.'.'.$number.'.'.$gender;
                $row[] = self::cell(LatinMorphology::adjectiveForm($entry, $key), \in_array($key, $highlight, true));
            }
            $rows[] = $row;
        }

        return self::html($rows);
    }

    /**
     * A pronoun's table for one number; a personal pronoun, which has no gender, shows both numbers.
     *
     * @param list<string> $highlight keys (`gen.pl.m`, or `gen.pl` for a personal pronoun)
     */
    public static function pronoun(string $lemma, string $number, array $highlight = []): string
    {
        $personal = LatinMorphology::isPersonal($lemma);
        $rows = $personal ? [['', 'Singulier', 'Pluriel']] : [['', 'Masculin', 'Féminin', 'Neutre']];
        foreach (LatinMorphology::CASES as $case) {
            if ('voc' === $case && [] === LatinMorphology::pronounForms($lemma, 'm', 'voc.sg')) {
                continue;
            }
            $row = [ucfirst(LatinMorphology::caseName($case))];
            if ($personal) {
                foreach (LatinMorphology::NUMBERS as $n) {
                    $row[] = self::cell(LatinMorphology::pronounForm($lemma, 'm', $case.'.'.$n), \in_array($case.'.'.$n, $highlight, true));
                }
            } else {
                foreach (LatinMorphology::GENDERS as $gender) {
                    $key = $case.'.'.$number.'.'.$gender;
                    $row[] = self::cell(LatinMorphology::pronounForm($lemma, $gender, $key), \in_array($key, $highlight, true));
                }
            }
            $rows[] = $row;
        }

        return self::html($rows);
    }

    private static function cell(string $form, bool $highlighted): string
    {
        if (LatinMorphology::NONE === $form) {
            return $form;
        }
        $italic = '<i>'.htmlspecialchars($form, \ENT_NOQUOTES).'</i>';

        return $highlighted ? '<b>'.$italic.'</b>' : $italic;
    }

    /** @param list<list<string>> $rows */
    private static function html(array $rows): string
    {
        $html = '<table class="sgm-t sgm-decl">';
        foreach ($rows as $r => $row) {
            $html .= '<tr>';
            foreach ($row as $c => $cell) {
                $tag = 0 === $r || 0 === $c ? 'th' : 'td';
                $html .= '<'.$tag.'>'.$cell.'</'.$tag.'>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }
}
