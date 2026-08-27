<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * A calendar month, as the game counts in.
 *
 * Points are credited on the day they are earned and counted in **the month that day falls in**
 * (2026-08-28). That replaces the evaluation period as the scoring window, and it removes the last
 * thing a team had to know before acting: a month is the same for everybody, needs no setting up,
 * and nobody has to ask which one they are in.
 *
 * The key is `YYYY-MM`, sortable as a string, which is what a ranking is stored and looked up by.
 */
final readonly class GameMonth
{
    private function __construct(
        public int $year,
        public int $month,
    ) {
    }

    public static function of(\DateTimeImmutable $day): self
    {
        return new self((int) $day->format('Y'), (int) $day->format('n'));
    }

    /** `YYYY-MM`, or null when the string is not one. */
    public static function fromKey(string $key): ?self
    {
        if (1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key, $matches)) {
            return null;
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function key(): string
    {
        return \sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function firstDay(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $this->year, $this->month)))->setTime(0, 0);
    }

    public function lastMoment(): \DateTimeImmutable
    {
        return $this->firstDay()->modify('last day of this month')->setTime(23, 59, 59);
    }

    public function previous(): self
    {
        return self::of($this->firstDay()->modify('-1 month'));
    }

    public function next(): self
    {
        return self::of($this->firstDay()->modify('+1 month'));
    }

    public function isBefore(self $other): bool
    {
        return $this->key() < $other->key();
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /** Has this month finished? Only a finished month can be closed and its top three paid. */
    public function hasEnded(?\DateTimeImmutable $now = null): bool
    {
        return $this->lastMoment() < ($now ?? new \DateTimeImmutable());
    }
}
