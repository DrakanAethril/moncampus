<?php

declare(strict_types=1);

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
     * The `seconds` of a « moncampus-quiz/1 » question: a whole number of seconds (5 to 3600) is
     * that question's own time, "unlimited" lifts the limit, and anything else - absent included -
     * leaves the quiz's own setting in charge.
     *
     * @return array{0: self, 1: int|null}
     */
    public static function fromDocument(mixed $seconds): array
    {
        if (\is_string($seconds) && \in_array(mb_strtolower(trim($seconds)), ['unlimited', 'illimite', 'illimité'], true)) {
            return [self::Unlimited, null];
        }
        if (is_numeric($seconds) && (int) $seconds >= 5 && (int) $seconds <= 3600) {
            return [self::Fixed, (int) $seconds];
        }

        return [self::Quiz, null];
    }

    /**
     * The same `seconds`, written back out - null when the question follows its quiz.
     */
    public function toDocument(?int $ownSeconds): int|string|null
    {
        return match ($this) {
            self::Quiz => null,
            self::Unlimited => 'unlimited',
            self::Fixed => $ownSeconds,
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
