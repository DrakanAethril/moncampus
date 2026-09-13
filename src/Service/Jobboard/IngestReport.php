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
 * Beside the four outcomes it carries what the pass **learned** about the sites themselves - a
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

    /** Offers of a blacklisted site: read, dropped, and counted only for the history. */
    public function blocked(): int
    {
        return $this->count(IngestOutcome::Blocked);
    }

    /** @return list<IngestLine> */
    public function rejectedLines(): array
    {
        return $this->linesOf(IngestOutcome::Rejected);
    }

    /**
     * What the import screen prints under « Offres ignorées » - and what the API's answer does not
     * carry. The asymmetry is deliberate: an administrator who put a site on the blacklist is
     * entitled to see the file's offers disappear under his own gesture, the collecting agent is
     * not concerned by a decision it cannot act on.
     *
     * @return list<IngestLine>
     */
    public function blockedLines(): array
    {
        return $this->linesOf(IngestOutcome::Blocked);
    }

    /**
     * The slugs of the blacklisted sites this pass met, each named once.
     *
     * What the import screen prints, and it is a list of *sites* rather than of lines on purpose:
     * the question an administrator answers there is « est-ce bien ce site que j'ai écarté ? », and
     * four hundred rows saying the same name would not help him answer it.
     *
     * @return list<string>
     */
    public function blockedSources(): array
    {
        $names = [];

        foreach ($this->blockedLines() as $line) {
            if (null !== $line->source) {
                $names[$line->source] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @return array<string, mixed>
     *
     * `sources` is always present, empty included: an agent parsing a stable shape is one that
     * cannot silently stop reading the day the key would have appeared. It is informational - the
     * offers it concerns were filed, and every accepted line already echoes the `source` retained.
     *
     * **The blocked lines are not here, neither counted nor echoed**, and that is the contract:
     * an offer from a blacklisted site is accepted by the API and dropped without a word. Saying
     * so would turn a decision of the establishment into an instruction to the collector, which
     * would then be right to stop collecting a site somebody may un-blacklist tomorrow. The figure
     * is not lost - it is on the batch, and « Configuration > Jobboard > Historique » prints it.
     */
    public function toArray(): array
    {
        $told = array_values(array_filter(
            $this->lines,
            static fn (IngestLine $line): bool => IngestOutcome::Blocked !== $line->outcome,
        ));

        return [
            'created' => $this->created(),
            'reviewed' => $this->reviewed(),
            'rejected' => $this->rejected(),
            'offers' => array_map(static fn (IngestLine $line): array => $line->toArray(), $told),
            'sources' => array_map(static fn (SourceLearning $learning): array => $learning->toArray(), $this->learned),
        ];
    }

    private function count(IngestOutcome $outcome): int
    {
        return \count($this->linesOf($outcome));
    }

    /** @return list<IngestLine> */
    private function linesOf(IngestOutcome $outcome): array
    {
        return array_values(array_filter($this->lines, static fn (IngestLine $line): bool => $outcome === $line->outcome));
    }
}
