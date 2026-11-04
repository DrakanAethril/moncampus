<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentChallengeRefusal;

/**
 * « A défie B » (design/validated/quiz-tournament.md §4.1), as pure functions on facts loaded
 * beforehand (QuizTournamentChallengeFacts). Every refusal is named
 * (App\Enum\QuizTournamentChallengeRefusal), so the « Défier » button says why it is greyed out.
 *
 * - the tournament runs, A and B are both eligible and two different people;
 * - A has no other outgoing challenge pending;
 * - B has fewer than five incoming challenges pending;
 * - B has not refused a challenge of A in the last 48 hours;
 * - A and B have not met three times today - a Paris civil day, both directions together, whatever
 *   became of each challenge (refused, expired, played), round duels included (§8, point 5).
 *
 * The 24-hour lifetimes of a challenge and of its room, and the « Prêt » heartbeat, live here too:
 * one place for the clock of §4.
 */
final class QuizTournamentChallengeRules
{
    public const int MAX_PENDING_INCOMING = 5;
    public const int REFUSAL_COOLDOWN_HOURS = 48;
    public const int DAILY_PAIR_CAP = 3;
    public const int ANSWER_HOURS = 24;
    public const int ROOM_HOURS = 24;
    public const int READY_TIMEOUT_SECONDS = 45;
    public const string TIMEZONE = 'Europe/Paris';

    /** @return list<QuizTournamentChallengeRefusal> every reason, in the order the screen explains them */
    public static function refusals(QuizTournamentChallengeFacts $facts, \DateTimeImmutable $now): array
    {
        $refusals = [];

        if (!$facts->inProgress) {
            $refusals[] = QuizTournamentChallengeRefusal::NotInProgress;
        }
        if (!$facts->challengerEligible) {
            $refusals[] = QuizTournamentChallengeRefusal::ChallengerNotEligible;
        }
        if ($facts->samePlayer) {
            $refusals[] = QuizTournamentChallengeRefusal::SamePlayer;
        } elseif (!$facts->challengedEligible) {
            $refusals[] = QuizTournamentChallengeRefusal::ChallengedNotEligible;
        }
        if ($facts->challengerPendingOutgoing > 0) {
            $refusals[] = QuizTournamentChallengeRefusal::OutgoingPending;
        }
        if ($facts->challengedPendingIncoming >= self::MAX_PENDING_INCOMING) {
            $refusals[] = QuizTournamentChallengeRefusal::TooManyIncoming;
        }
        if (null !== $facts->lastRefusalByChallengedAt
            && $facts->lastRefusalByChallengedAt > $now->modify(\sprintf('-%d hours', self::REFUSAL_COOLDOWN_HOURS))) {
            $refusals[] = QuizTournamentChallengeRefusal::RecentlyRefused;
        }
        if (self::isAtDailyCap($facts->pairEncounters, $now)) {
            $refusals[] = QuizTournamentChallengeRefusal::DailyPairCap;
        }

        return $refusals;
    }

    public static function canChallenge(QuizTournamentChallengeFacts $facts, \DateTimeImmutable $now): bool
    {
        return [] === self::refusals($facts, $now);
    }

    /** The reason the button shows, null when it is not greyed out. */
    public static function firstRefusal(QuizTournamentChallengeFacts $facts, \DateTimeImmutable $now): ?QuizTournamentChallengeRefusal
    {
        return self::refusals($facts, $now)[0] ?? null;
    }

    /** @param list<\DateTimeImmutable> $encounters */
    public static function encountersToday(array $encounters, \DateTimeImmutable $now): int
    {
        [$from, $to] = self::dayBounds($now);

        return \count(array_filter($encounters, static fn (\DateTimeImmutable $at): bool => $at >= $from && $at < $to));
    }

    /** @param list<\DateTimeImmutable> $encounters */
    public static function isAtDailyCap(array $encounters, \DateTimeImmutable $now): bool
    {
        return self::encountersToday($encounters, $now) >= self::DAILY_PAIR_CAP;
    }

    /**
     * The Paris civil day holding this moment: its midnight, and the next one (excluded) - what a
     * query loading the day's encounters filters on.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function dayBounds(\DateTimeImmutable $now): array
    {
        $from = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);

        return [$from, $from->modify('+1 day')];
    }

    /** A challenge nobody answered expires 24 hours after it was sent. */
    public static function expiresAt(\DateTimeImmutable $sentAt): \DateTimeImmutable
    {
        return $sentAt->modify(\sprintf('+%d hours', self::ANSWER_HOURS));
    }

    /** The room of an accepted challenge stays open 24 hours. */
    public static function roomClosesAt(\DateTimeImmutable $acceptedAt): \DateTimeImmutable
    {
        return $acceptedAt->modify(\sprintf('+%d hours', self::ROOM_HOURS));
    }

    /**
     * « Prêt » holds while one is in the room: the page beats every 20 seconds, and a beat older than
     * 45 seconds means the player left.
     */
    public static function isStillReady(?\DateTimeImmutable $lastBeatAt, \DateTimeImmutable $now): bool
    {
        return null !== $lastBeatAt && $now->getTimestamp() - $lastBeatAt->getTimestamp() <= self::READY_TIMEOUT_SECONDS;
    }
}
