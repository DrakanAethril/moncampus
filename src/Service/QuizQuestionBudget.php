<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The per-question time budget, applied by the server.
 *
 * QuizInstance::$secondsPerQuestion used to live only in quiz_passation_controller.js, where a
 * reload handed out a brand-new countdown as many times as the student wanted. With the serving
 * instant written down (App\Entity\QuizAttemptAnswer::$servedAt, stamped once and only once), the
 * budget becomes a comparison the server can make - the same way QuizAttempt::isPastTimeLimit()
 * already applies the global one.
 *
 * Pure statics over primitives rather than a service reading entities: the rule is a subtraction on
 * two instants and an integer, and that is exactly what its test should have to build.
 */
final class QuizQuestionBudget
{
    /**
     * How much lateness the budget forgives.
     *
     * The browser's countdown submits the form the instant it reaches zero, so the POST always
     * lands slightly *after* the deadline - request time, a slow render, a phone waking up. Without
     * this margin the server would refuse the very answers its own client sent on time, which is
     * the opposite of what the budget is for. Five seconds is far below the twenty a search costs
     * (see the design's §4), so it buys nothing to whoever is looking for it.
     */
    public const int GRACE_SECONDS = 5;

    /**
     * When this question stops accepting an answer, grace included. Null when nothing bounds it:
     * an unlimited question, or one that has never been served.
     */
    public static function deadline(?\DateTimeImmutable $servedAt, ?int $seconds): ?\DateTimeImmutable
    {
        if (null === $servedAt || null === $seconds || $seconds <= 0) {
            return null;
        }

        return $servedAt->modify(\sprintf('+%d seconds', $seconds + self::GRACE_SECONDS));
    }

    /** True when an answer arriving now is past this question's budget and must not be recorded. */
    public static function isLate(?\DateTimeImmutable $servedAt, ?int $seconds, \DateTimeImmutable $now): bool
    {
        $deadline = self::deadline($servedAt, $seconds);

        return null !== $deadline && $now > $deadline;
    }

    /**
     * The seconds still left to answer, for a client that wants to show a countdown starting where
     * the question actually began rather than at the full budget. Null when unbounded; never
     * negative - a question already past its budget has zero seconds left, not minus twelve.
     */
    public static function remainingSeconds(?\DateTimeImmutable $servedAt, ?int $seconds, \DateTimeImmutable $now): ?int
    {
        if (null === $servedAt || null === $seconds || $seconds <= 0) {
            return null;
        }

        return max(0, $seconds - ($now->getTimestamp() - $servedAt->getTimestamp()));
    }
}
