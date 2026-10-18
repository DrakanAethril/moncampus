<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Latin\LatinMorphology;

/**
 * A Latin form typed by the student (Les Tablettes de Pompéi): compared without case, macrons or
 * breves, i for j and u for v - « rosā », « Rosa » and « rosa » are one answer. A value may list
 * several accepted forms (an i-stem's accusative plural in -es or -is), the first being the one
 * shown.
 */
final class LatinChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Latin === $type;
    }

    /** @return list<string> */
    public function value(mixed $raw, AnswerSpec $spec): array
    {
        return self::alternatives($raw);
    }

    public function read(string $input, AnswerSpec $spec): ?string
    {
        $text = trim($input);

        // a form is letters, a space at most (« cum amicis »): digits or signs do not read as Latin
        return '' === $text || !preg_match('/^[\p{L}\p{Mn}\s\'’-]+$/u', $text) ? null : $text;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return self::matches($a, $b);
    }

    /**
     * Whether two values share a form - each a form or a list of accepted forms.
     */
    public static function matches(mixed $a, mixed $b): bool
    {
        $left = \is_array($a) ? $a : [$a];
        $right = \is_array($b) ? $b : [$b];
        foreach ($left as $x) {
            foreach ($right as $y) {
                if (\is_string($x) && \is_string($y) && LatinMorphology::same($x, $y)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function alternatives(mixed $raw): array
    {
        $forms = [];
        foreach (\is_array($raw) ? $raw : [$raw] as $form) {
            if (!\is_string($form) || '' === trim($form)) {
                throw new \InvalidArgumentException('une forme latine (ou une liste de formes acceptées) était attendue');
            }
            $forms[] = trim($form);
        }
        if ([] === $forms) {
            throw new \InvalidArgumentException('au moins une forme latine était attendue');
        }

        return $forms;
    }
}
