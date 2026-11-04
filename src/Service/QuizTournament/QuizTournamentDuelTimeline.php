<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentDuelPhase;

/**
 * A duel's questions on its clock (QuizTournamentDuelClock::timeline()), and what it says at a given
 * moment: the phase, the question in play, when the screen should ask again, which serves are due to
 * be stamped, whether an answer is still taken, whether the duel is over.
 */
final readonly class QuizTournamentDuelTimeline
{
    /** @param list<QuizTournamentDuelMoment> $moments in rank order */
    public function __construct(
        public array $moments,
        public \DateTimeImmutable $endsAt,
    ) {
    }

    public function isOver(\DateTimeImmutable $now): bool
    {
        return $now >= $this->endsAt;
    }

    public function phaseAt(\DateTimeImmutable $now): QuizTournamentDuelPhase
    {
        if ($this->isOver($now)) {
            return QuizTournamentDuelPhase::Finished;
        }
        if ([] === $this->moments || $now < $this->moments[0]->servedAt) {
            return QuizTournamentDuelPhase::Waiting;
        }

        $moment = $this->momentAt($now);

        return null !== $moment && $now < $moment->closesAt ? QuizTournamentDuelPhase::Question : QuizTournamentDuelPhase::Reveal;
    }

    /** The question in play - open, or in its two seconds after - or null before the first and once over. */
    public function rankAt(\DateTimeImmutable $now): ?int
    {
        if ($this->isOver($now)) {
            return null;
        }

        return $this->momentAt($now)?->rank;
    }

    /** When what the screen shows changes next on its own - the moment it should ask again. */
    public function nextChangeAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($this->isOver($now)) {
            return null;
        }

        foreach ($this->moments as $index => $moment) {
            if ($now < $moment->servedAt) {
                return $moment->servedAt;
            }
            if ($now < $moment->closesAt) {
                return $moment->closesAt;
            }
            $following = $this->moments[$index + 1] ?? null;
            if (null !== $following && $now < $following->servedAt) {
                return $following->servedAt;
            }
        }

        return $this->endsAt;
    }

    /**
     * The serves to stamp now: every question whose time has come and has no stored serve yet, at
     * the time it came.
     *
     * @return array<int, \DateTimeImmutable> rank => servedAt
     */
    public function dueToServe(\DateTimeImmutable $now): array
    {
        $due = [];
        foreach ($this->moments as $moment) {
            if (!$moment->stamped && $moment->servedAt <= $now) {
                $due[$moment->rank] = $moment->servedAt;
            }
        }

        return $due;
    }

    /** Is an answer to this question still taken: served, and its deadline not passed. */
    public function acceptsAnswer(int $rank, \DateTimeImmutable $now): bool
    {
        $moment = $this->moments[$rank] ?? null;

        return null !== $moment && $moment->servedAt <= $now && $now <= $moment->deadlineAt;
    }

    /** Has this question closed by now - its right/wrong may be told. */
    public function isClosed(int $rank, \DateTimeImmutable $now): bool
    {
        $moment = $this->moments[$rank] ?? null;

        return null !== $moment && $moment->closesAt <= $now;
    }

    /** The last question served by now. */
    private function momentAt(\DateTimeImmutable $now): ?QuizTournamentDuelMoment
    {
        $current = null;
        foreach ($this->moments as $moment) {
            if ($moment->servedAt > $now) {
                break;
            }
            $current = $moment;
        }

        return $current;
    }
}
