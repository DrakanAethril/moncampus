<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * A sequence of letters typed by the student - a strand of DNA or a messenger RNA (Station
 * Abysse): read without case, spaces, dashes or dots, so « ATG CCA », « atgcca » and « A-T-G-C-C-A »
 * are one answer. Anything but letters does not read.
 */
final class SequenceChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Sequence === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): string
    {
        if (!\is_string($raw) || '' === self::fold($raw)) {
            throw new \InvalidArgumentException('une suite de lettres était attendue');
        }

        return self::fold($raw);
    }

    public function read(string $input, AnswerSpec $spec): ?string
    {
        $text = trim($input);
        if ('' === $text || !preg_match('/^[\p{L}\s.\-·]+$/u', $text)) {
            return null;
        }

        return self::fold($text);
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return \is_string($a) && \is_string($b) && self::fold($a) === self::fold($b);
    }

    public static function fold(string $sequence): string
    {
        return mb_strtoupper((string) preg_replace('/[\s.\-·]+/u', '', $sequence));
    }
}
