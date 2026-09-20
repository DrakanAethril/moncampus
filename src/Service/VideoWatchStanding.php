<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Where one student stands on one video resource, all its files together.
 *
 * It exists because two screens read the same rows and used to answer differently: the tool's
 * « Suivi de visionnage » knew three states and a percentage, while the travail's follow-up knew
 * « Visionné » or nothing - so a class halfway through a twenty-minute lecture was announced as
 * having done nothing at all. The reading is written once here and both screens ask it.
 *
 * The percentage is weighted by running time, as App\Service\VideoWatchTracker weighs it: a
 * twelve-minute lecture and a thirty-second outro are not half the set each.
 */
final class VideoWatchStanding
{
    public const string NOT_STARTED = 'not_started';
    public const string IN_PROGRESS = 'in_progress';
    public const string COMPLETE = 'complete';

    /** @param array<int, int> $percents file id => furthest point ever reached, in percent */
    public function __construct(
        public readonly array $percents,
        public readonly int $percent,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $lastWatchedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly int $watchedSeconds = 0,
        public readonly int $skipCount = 0,
        public readonly int $focusLossCount = 0,
    ) {
    }

    /**
     * « Terminé » at 100 % on EVERY file and only then - the completion rule the travail is settled
     * by, not a more forgiving reading kept for the teacher.
     */
    public function isComplete(): bool
    {
        return [] !== $this->percents && [] === array_filter($this->percents, static fn (int $percent): bool => $percent < 100);
    }

    /** Anything played at all: a single second on one file of the set opens the « En cours » state. */
    public function hasStarted(): bool
    {
        return [] !== array_filter($this->percents, static fn (int $percent): bool => $percent > 0);
    }

    public function status(): string
    {
        return match (true) {
            $this->isComplete() => self::COMPLETE,
            $this->hasStarted() => self::IN_PROGRESS,
            default => self::NOT_STARTED,
        };
    }
}
