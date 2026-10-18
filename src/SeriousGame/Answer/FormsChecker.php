<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * A table to fill (Les Tablettes de Pompéi): several Latin forms at once - the six cases of a
 * number, in the order of the answer's `labels`. The game draws one field per label and sends the
 * cells joined by « | »; a student who types the table on one line may separate the cells with
 * « ; », « , » or « · ». Every cell is compared as a Latin form (LatinChecker), and the table is
 * right only when every cell is.
 */
final class FormsChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Forms === $type;
    }

    /** @return list<list<string>> */
    public function value(mixed $raw, AnswerSpec $spec): array
    {
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) < 2) {
            throw new \InvalidArgumentException('une liste de formes (une par case) était attendue');
        }

        return array_map(LatinChecker::alternatives(...), $raw);
    }

    /** @return list<string>|null */
    public function read(string $input, AnswerSpec $spec): ?array
    {
        $cells = array_map('trim', preg_split('/[|;,·\n]/u', $input) ?: []);
        $expected = [] === $spec->labels ? null : \count($spec->labels);
        if (null !== $expected && \count($cells) !== $expected || \count($cells) < 2) {
            return null;
        }
        foreach ($cells as $cell) {
            if ('' === $cell || !preg_match('/^[\p{L}\p{Mn}\s\'’—-]+$/u', $cell)) {
                return null;
            }
        }

        return $cells;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        if (!\is_array($a) || !\is_array($b) || \count($a) !== \count($b)) {
            return false;
        }
        foreach (array_values($a) as $i => $cell) {
            if (!LatinChecker::matches($cell, array_values($b)[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which cells of what was typed are right - the correction shows the others.
     *
     * @param list<string>       $typed
     * @param list<list<string>> $expected
     *
     * @return list<bool>
     */
    public static function cells(array $typed, array $expected): array
    {
        return array_map(static fn (int $i): bool => isset($typed[$i]) && LatinChecker::matches($typed[$i], $expected[$i]), array_keys($expected));
    }
}
