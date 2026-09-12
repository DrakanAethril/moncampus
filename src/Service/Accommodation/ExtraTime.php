<?php

declare(strict_types=1);

namespace App\Service\Accommodation;

/**
 * The arithmetic of « ajout de temps », kept away from the entities that call it.
 *
 * Pure statics over primitives rather than a service reading entities, exactly like
 * App\Service\QuizQuestionBudget: the rule is a multiplication and a ceiling, and that is all its
 * test should have to build.
 *
 * **Rounding is always up, to the whole second.** A student granted a third more on a 30 s question
 * gets 40 s, and one granted it on a 25 s question gets 34 s rather than 33 s and a third: the extra
 * time exists to be sufficient, so the fraction goes to the person it was granted to.
 */
final class ExtraTime
{
    /**
     * The budget $seconds becomes once $percent is added to it. Null in, null out: a question or a
     * quiz with no time limit does not acquire one by being sat by someone with extra time.
     */
    public static function apply(?int $seconds, float $percent): ?int
    {
        if (null === $seconds || $percent <= 0.0) {
            return $seconds;
        }

        return (int) ceil($seconds * (1 + $percent / 100));
    }

    /** How many seconds $percent adds to $seconds - the granted time on its own, for a message that says so. */
    public static function addedSeconds(?int $seconds, float $percent): int
    {
        if (null === $seconds || $percent <= 0.0) {
            return 0;
        }

        return (int) ceil($seconds * (1 + $percent / 100)) - $seconds;
    }

    /**
     * A percentage as it is written on screen: « 33,33 % », « 50 % ». Trailing zeros are dropped -
     * an accommodation typed as "50" must not read as "50,00 %", which looks like a precision
     * nobody claimed.
     */
    public static function percentLabel(float $percent): string
    {
        $formatted = number_format($percent, 2, ',', ' ');

        if (str_ends_with($formatted, ',00')) {
            $formatted = substr($formatted, 0, -3);
        } elseif (str_ends_with($formatted, '0')) {
            $formatted = substr($formatted, 0, -1);
        }

        return $formatted.' %';
    }
}
