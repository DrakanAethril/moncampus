<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a quiz tournament stands in its life (design/validated/quiz-tournament.md §3), read - never
 * stored: born **closed** (the administrators alone see it), then, once opened, before its start
 * (« Commence le … »), during its period, or over (the standings frozen at its end).
 */
enum QuizTournamentStatus: string implements HasBadge
{
    case Closed = 'closed';
    case Upcoming = 'upcoming';
    case InProgress = 'in_progress';
    case Ended = 'ended';

    public static function of(bool $opened, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): self
    {
        return match (true) {
            !$opened => self::Closed,
            $now < $startsAt => self::Upcoming,
            $now < $endsAt => self::InProgress,
            default => self::Ended,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Closed => 'quizTournamentStatusClosed',
            self::Upcoming => 'quizTournamentStatusUpcoming',
            self::InProgress => 'quizTournamentStatusInProgress',
            self::Ended => 'quizTournamentStatusEnded',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Closed => BadgeTone::Gray,
            self::Upcoming => BadgeTone::Blue,
            self::InProgress => BadgeTone::Green,
            self::Ended => BadgeTone::Purple,
        };
    }
}
