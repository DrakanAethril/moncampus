<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * The wrong word of a line, pointed at (The Irregulars: « Click the wrong word, or confirm the line
 * is correct »). The line's words are the answer's `labels`; the value is the place of the wrong
 * word, counted from 0, or −1 when the line is correct. The game sends the place clicked, or
 * « correct ».
 *
 * A typical wrong answer may be written −2: « any word », what a student does who finds an error in
 * a correct line. It never equals a place, so it is kept only where the answer is −1.
 */
final class SpotChecker implements AnswerChecker
{
    public const int CORRECT = -1;
    public const int ANY_WORD = -2;

    public function supports(AnswerType $type): bool
    {
        return AnswerType::Spot === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): int
    {
        if (!\is_int($raw) && !(\is_float($raw) && floor($raw) === $raw)) {
            throw new \InvalidArgumentException('la place du mot fautif (à partir de 0), −1 pour une phrase correcte, était attendue');
        }
        $place = (int) $raw;
        if ($place < self::ANY_WORD || ([] !== $spec->labels && $place >= \count($spec->labels))) {
            throw new \InvalidArgumentException('la place '.$place.' ne désigne aucun mot de la phrase');
        }

        return $place;
    }

    public function read(string $input, AnswerSpec $spec): ?int
    {
        $text = trim($input);
        if ('correct' === $text) {
            return self::CORRECT;
        }
        if (!preg_match('/^\d+$/', $text)) {
            return null;
        }
        $place = (int) $text;

        return [] !== $spec->labels && $place >= \count($spec->labels) ? null : $place;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        if (!\is_int($a) || !\is_int($b)) {
            return false;
        }
        if (self::ANY_WORD === $a || self::ANY_WORD === $b) {
            $other = self::ANY_WORD === $a ? $b : $a;

            return $other >= 0;
        }

        return $a === $b;
    }
}
