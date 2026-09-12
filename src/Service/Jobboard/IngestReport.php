<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

/**
 * What one call did, line by line and in total.
 *
 * The totals are counted from the lines rather than incremented along the way: two numbers that can
 * disagree eventually do, and this one is the number an administrator reads to decide whether the
 * veille is working.
 */
final readonly class IngestReport
{
    /** @param list<IngestLine> $lines */
    public function __construct(public array $lines)
    {
    }

    public function created(): int
    {
        return $this->count(IngestOutcome::Created);
    }

    public function reviewed(): int
    {
        return $this->count(IngestOutcome::Reviewed);
    }

    public function rejected(): int
    {
        return $this->count(IngestOutcome::Rejected);
    }

    /** @return list<IngestLine> */
    public function rejectedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (IngestLine $line): bool => IngestOutcome::Rejected === $line->outcome));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created' => $this->created(),
            'reviewed' => $this->reviewed(),
            'rejected' => $this->rejected(),
            'offers' => array_map(static fn (IngestLine $line): array => $line->toArray(), $this->lines),
        ];
    }

    private function count(IngestOutcome $outcome): int
    {
        return \count(array_filter($this->lines, static fn (IngestLine $line): bool => $outcome === $line->outcome));
    }
}
