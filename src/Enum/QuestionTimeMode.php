<?php

namespace App\Enum;

/**
 * How long a single question is given, relative to the quiz it belongs to.
 *
 * Two levels answer the same question and this enum is what tells them apart: the quiz carries a
 * default (QuizTemplate::$defaultSecondsPerQuestion, null meaning "no limit"), and each question
 * either follows it, overrides it with its own count, or lifts the limit for itself alone. A
 * separate mode rather than "null seconds means unlimited" on the question: on a question, null
 * has to keep meaning "whatever the quiz says", which is a third answer.
 */
enum QuestionTimeMode: string
{
    case Quiz = 'quiz';
    case Unlimited = 'unlimited';
    case Fixed = 'fixed';

    public function labelKey(): string
    {
        return match ($this) {
            self::Quiz => 'questionTimeModeQuizLabel',
            self::Unlimited => 'questionTimeModeUnlimitedLabel',
            self::Fixed => 'questionTimeModeFixedLabel',
        };
    }

    /**
     * The seconds a question actually gets, null meaning no limit at all.
     *
     * @param int|null $ownSeconds  the question's own count, only read in Fixed mode
     * @param int|null $quizSeconds the quiz's default, null when the quiz itself is unlimited
     */
    public function resolveSeconds(?int $ownSeconds, ?int $quizSeconds): ?int
    {
        return match ($this) {
            self::Quiz => $quizSeconds,
            self::Unlimited => null,
            // Fixed without a count is a half-filled form, not a lifted limit - falling back to the
            // quiz keeps a question that says "défini" from silently becoming untimed.
            self::Fixed => $ownSeconds ?? $quizSeconds,
        };
    }
}
