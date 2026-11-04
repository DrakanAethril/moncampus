<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournamentChallenge;
use App\Entity\User;
use App\Enum\QuizTournamentChallengeStatus;

/**
 * « Mes défis » (design/validated/quiz-tournament.md §7), read at a moment from one person's
 * challenges in one tournament - pure, no query:
 *
 * 1. **rooms** - the accepted challenges whose room is still open, the one where the opponent is
 *    already ready (« Il t'attend ! ») first, then the soonest to close;
 * 2. **received** - the challenges awaiting this person's answer, the soonest to expire first;
 * 3. **sent** - this person's challenge awaiting an answer, and for a day those that were refused
 *    or expired (« A voit le défi en attente, puis son issue », §4.2), the latest first.
 *
 * The tab's counter is received + rooms: what asks something of the person, never what they sent.
 * Every status is statusAt($now): a pending challenge past 24 h, or anything past the tournament's
 * end, is already closed whether or not its row was rewritten.
 */
final readonly class QuizTournamentChallengeBoard
{
    /** How long an answered (or expired) sent challenge stays in « Envoyés ». */
    public const int OUTCOME_HOURS = 24;

    /**
     * @param list<QuizTournamentChallenge> $rooms
     * @param list<QuizTournamentChallenge> $received
     * @param list<QuizTournamentChallenge> $sent
     */
    private function __construct(
        private User $reader,
        public array $rooms,
        public array $received,
        public array $sent,
    ) {
    }

    /** @param list<QuizTournamentChallenge> $challenges this person's challenges, either side */
    public static function of(array $challenges, User $reader, \DateTimeImmutable $now): self
    {
        $rooms = [];
        $received = [];
        $sent = [];
        $since = $now->modify(\sprintf('-%d hours', self::OUTCOME_HOURS));

        foreach ($challenges as $challenge) {
            if (!$challenge->involves($reader)) {
                continue;
            }
            $status = $challenge->statusAt($now);
            $mine = $challenge->getChallenger() === $reader;

            if (QuizTournamentChallengeStatus::Accepted === $status) {
                $rooms[] = $challenge;
            } elseif (QuizTournamentChallengeStatus::Pending === $status) {
                if ($mine) {
                    $sent[] = $challenge;
                } else {
                    $received[] = $challenge;
                }
            } elseif ($mine && \in_array($status, [QuizTournamentChallengeStatus::Refused, QuizTournamentChallengeStatus::Expired], true)
                && self::closedAt($challenge) >= $since) {
                $sent[] = $challenge;
            }
        }

        usort($rooms, static function (QuizTournamentChallenge $a, QuizTournamentChallenge $b) use ($reader, $now): int {
            $waitingA = self::opponentWaiting($a, $reader, $now);
            $waitingB = self::opponentWaiting($b, $reader, $now);

            return [$waitingB, $a->getRoomClosesAt(), $a->getId()] <=> [$waitingA, $b->getRoomClosesAt(), $b->getId()];
        });
        usort($received, static fn (QuizTournamentChallenge $a, QuizTournamentChallenge $b): int => [$a->getExpiresAt(), $a->getId()] <=> [$b->getExpiresAt(), $b->getId()]);
        usort($sent, static fn (QuizTournamentChallenge $a, QuizTournamentChallenge $b): int => [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()]);

        return new self($reader, $rooms, $received, $sent);
    }

    /**
     * The « Défis » tab's counter: received challenges awaiting an answer, plus open rooms.
     *
     * @param list<QuizTournamentChallenge> $challenges
     */
    public static function countFor(array $challenges, User $reader, \DateTimeImmutable $now): int
    {
        return self::of($challenges, $reader, $now)->count();
    }

    public function count(): int
    {
        return \count($this->received) + \count($this->rooms);
    }

    public function isEmpty(): bool
    {
        return [] === $this->rooms && [] === $this->received && [] === $this->sent;
    }

    /** « Il t'attend ! »: the other player is in the room, ready. */
    public function isOpponentWaiting(QuizTournamentChallenge $room, \DateTimeImmutable $now): bool
    {
        return self::opponentWaiting($room, $this->reader, $now);
    }

    private static function opponentWaiting(QuizTournamentChallenge $room, User $reader, \DateTimeImmutable $now): bool
    {
        $opponent = $room->opponentOf($reader);

        return null !== $opponent && $room->isReady($opponent, $now);
    }

    /** When a sent challenge stopped waiting: its answer, else its expiry or the tournament's end. */
    private static function closedAt(QuizTournamentChallenge $challenge): \DateTimeImmutable
    {
        return $challenge->getRespondedAt() ?? min($challenge->getExpiresAt(), $challenge->getTournament()->getEndsAt());
    }
}
