<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * The correction of one step (design/validated/serious-games-neko-con.md §3.2): right or not, the
 * misconception a wrong explanation names, what the requester or Inès says, and what is kept of the
 * input - the option chosen, the line designated, the failed criteria of a delivery. Never the
 * delivered code, never the password typed (§3.4).
 *
 * @phpstan-import-type Reaction from ScenarioStep
 */
final readonly class ScenarioGrade
{
    /** @param Reaction|null $reaction */
    public function __construct(
        public bool $ok,
        public ?string $misconception,
        public ?array $reaction,
        public ?string $stored,
    ) {
    }
}
