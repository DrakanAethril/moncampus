<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * Reads and compares one type of answer (design/validated/serious-games.md §5.2). A checker knows
 * three things:
 *
 * - value(): the canonical form of a value the template computed (a number, a list, an interval…),
 *   refusing a value of the wrong shape - which is a defect of the template, not of the student;
 * - read(): what the student typed, in that form, or null when it does not read;
 * - same(): whether two values are the same answer - equivalent writings included.
 */
interface AnswerChecker
{
    public function supports(AnswerType $type): bool;

    /** @throws \InvalidArgumentException when the template's value has the wrong shape */
    public function value(mixed $raw, AnswerSpec $spec): mixed;

    public function read(string $input, AnswerSpec $spec): mixed;

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool;
}
