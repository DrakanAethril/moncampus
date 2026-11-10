<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/** One sentence of a broken rule, as a translation key and its parameters. */
final readonly class SeatingProblem
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public string $key,
        public array $params = [],
    ) {
    }
}
