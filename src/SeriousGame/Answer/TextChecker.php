<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * A word or a short text (The Irregulars: « stopped », « has just left »), compared without case,
 * without the spaces around it, the final full stop or the curly apostrophe a keyboard types
 * instead of the straight one. A value may list several accepted texts, the first being the one
 * shown - British and American spellings (« travelled », « traveled »), « didn’t » and
 * « did not ».
 */
final class TextChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Text === $type;
    }

    /** @return list<string> */
    public function value(mixed $raw, AnswerSpec $spec): array
    {
        $values = \is_array($raw) ? array_values($raw) : [$raw];
        $out = [];
        foreach ($values as $value) {
            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                throw new \InvalidArgumentException('un texte (ou une liste de textes acceptés) était attendu');
            }
            if ('' !== trim((string) $value)) {
                $out[] = (string) $value;
            }
        }
        if ([] === $out) {
            throw new \InvalidArgumentException('un texte non vide était attendu');
        }

        return $out;
    }

    public function read(string $input, AnswerSpec $spec): ?string
    {
        $text = trim($input);

        return '' === $text ? null : $text;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        foreach (\is_array($a) ? $a : [$a] as $x) {
            foreach (\is_array($b) ? $b : [$b] as $y) {
                if (\is_string($x) && \is_string($y) && self::fold($x) === self::fold($y)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function fold(string $text): string
    {
        $text = str_replace(['’', '‘', '`'], "'", $text);
        $text = (string) preg_replace('/\s+/u', ' ', trim($text));

        return mb_strtolower(rtrim($text, ' .!?'));
    }
}
