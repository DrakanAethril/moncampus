<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a launched quiz stands in its own window, which is what a teacher sorts by on the Quiz par
 * classes screen (App\Controller\QuizTrackingController).
 *
 * It is derived, never stored: a QuizInstance only carries $opensAt/$closesAt, both nullable, and
 * an unbounded side simply means "since always" / "until further notice". Deriving it keeps the
 * screen honest at the second the window opens or closes, with nothing to migrate and no cron to
 * flip a column.
 */
enum QuizInstanceState: string
{
    case Ongoing = 'ongoing';
    case Scheduled = 'scheduled';
    case Finished = 'finished';

    /**
     * Both bounds are inclusive - a quiz is open on the second it opens and still open on the
     * second it closes. Closed wins over not-yet-open, so an inverted window (a data error) reads
     * as finished rather than sitting in the list the screen opens on.
     */
    public static function of(?\DateTimeImmutable $opensAt, ?\DateTimeImmutable $closesAt, \DateTimeImmutable $now): self
    {
        if (null !== $closesAt && $closesAt < $now) {
            return self::Finished;
        }

        if (null !== $opensAt && $opensAt > $now) {
            return self::Scheduled;
        }

        return self::Ongoing;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Ongoing => 'quizInstanceStateOngoingLabel',
            self::Scheduled => 'quizInstanceStateScheduledLabel',
            self::Finished => 'quizInstanceStateFinishedLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Ongoing => 'bg-green-lt',
            self::Scheduled => 'bg-blue-lt',
            self::Finished => 'bg-secondary-lt',
        };
    }
}
