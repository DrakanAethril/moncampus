<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Latin\LatinMorphology;

/**
 * A choice among the propositions of a question (a QCM): the game sends the text of the
 * proposition chosen, compared without case or accents. The propositions are the answer and the
 * question's typical wrong answers - each one an error the student can be recognised making.
 */
final class ChoiceChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Choice === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): string
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            throw new \InvalidArgumentException('le texte d’une proposition était attendu');
        }

        return trim($raw);
    }

    public function read(string $input, AnswerSpec $spec): ?string
    {
        $text = trim($input);

        return '' === $text ? null : $text;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return \is_string($a) && \is_string($b) && LatinMorphology::fold($a) === LatinMorphology::fold($b);
    }
}
