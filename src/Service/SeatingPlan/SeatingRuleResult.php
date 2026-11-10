<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/**
 * One rule, or one preference ($preference: `mix` or `front`), and whether the plan keeps it.
 */
final readonly class SeatingRuleResult
{
    public const string PREFERENCE_MIX = 'mix';
    public const string PREFERENCE_FRONT = 'front';

    /**
     * @param list<SeatingProblem> $problems
     */
    public function __construct(
        public ?SeatingRule $rule,
        public ?string $preference,
        public bool $ok,
        public array $problems,
    ) {
    }
}
