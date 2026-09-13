<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

/**
 * What one call did, line by line and in total.
 *
 * The totals are counted from the lines rather than incremented along the way: two numbers that can
 * disagree eventually do, and this one is the number an administrator reads to decide whether the
 * veille is working.
 *
 * Beside the three outcomes it carries what the pass **learned** about the sites themselves - a
 * site created, a domain attached (App\Service\Jobboard\SourceLearning). None of it is an outcome
 * of a line: one creation can serve four hundred offers, so it is counted per deposit and not per
 * offer, and it changes no tally.
 */
final readonly class IngestReport
{
    /**
     * @param list<IngestLine>     $lines
     * @param list<SourceLearning> $learned
     */
    public function __construct(public array $lines, public array $learned = [])
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

    /**
     * @return array<string, mixed>
     *
     * `sources` is always present, empty included: an agent parsing a stable shape is one that
     * cannot silently stop reading the day the key would have appeared. It is informational - the
     * offers it concerns were filed, and every accepted line already echoes the `source` retained.
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created(),
            'reviewed' => $this->reviewed(),
            'rejected' => $this->rejected(),
            'offers' => array_map(static fn (IngestLine $line): array => $line->toArray(), $this->lines),
            'sources' => array_map(static fn (SourceLearning $learning): array => $learning->toArray(), $this->learned),
        ];
    }

    private function count(IngestOutcome $outcome): int
    {
        return \count(array_filter($this->lines, static fn (IngestLine $line): bool => $outcome === $line->outcome));
    }
}
