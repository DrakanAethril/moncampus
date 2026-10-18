<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * A word or a short text, compared without case and without the spaces around it. No game uses it
 * yet; it is declared so that one asking a word does not have to dress it as a number.
 */
final class TextChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Text === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): string
    {
        if (!\is_string($raw) && !\is_int($raw) && !\is_float($raw)) {
            throw new \InvalidArgumentException('un texte était attendu');
        }

        return (string) $raw;
    }

    public function read(string $input, AnswerSpec $spec): ?string
    {
        $text = trim($input);

        return '' === $text ? null : $text;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return \is_string($a) && \is_string($b) && self::fold($a) === self::fold($b);
    }

    private static function fold(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text)));
    }
}
