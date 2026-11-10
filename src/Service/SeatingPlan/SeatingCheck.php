<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/**
 * What App\Service\SeatingPlan\SeatingRuleChecker found on a plan: one result per rule and per
 * active preference, the seats to draw in red, and the penalty the solver minimises.
 */
final readonly class SeatingCheck
{
    /**
     * @param list<SeatingRuleResult> $results
     * @param list<string>            $faultySeats
     */
    public function __construct(
        public int $penalty,
        public array $results,
        public array $faultySeats,
    ) {
    }

    /** How many rules - never preferences - are broken. */
    public function brokenRuleCount(): int
    {
        return \count(array_filter($this->results, static fn (SeatingRuleResult $result): bool => null !== $result->rule && !$result->ok));
    }

    public function ruleCount(): int
    {
        return \count(array_filter($this->results, static fn (SeatingRuleResult $result): bool => null !== $result->rule));
    }
}
