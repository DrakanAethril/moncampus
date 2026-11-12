<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;

/**
 * A source formation and the formation of the target year it is created as or completed into.
 *
 * `target` is null only in the planner's dry run, for a formation the import would create. `team`
 * and `options` are what the target formation holds once the import is done - what it had plus
 * what the team and shell copiers add - so that holders, referents and validators are only ever
 * set on members of the target team (R6), and option-scoped rows only on options it carries.
 */
final class ProgramPair
{
    /** @var array<int, User> */
    public array $team = [];

    /** @var array<int, Option> */
    public array $options = [];

    public function __construct(
        public readonly Program $source,
        public ?Program $target,
        public readonly bool $created,
        public readonly bool $ambiguous,
        public readonly string $name,
    ) {
    }

    public function key(): int
    {
        return (int) $this->source->getId();
    }

    public function inTeam(?User $user): bool
    {
        return null !== $user && isset($this->team[(int) $user->getId()]);
    }

    public function hasOption(?Option $option): bool
    {
        return null !== $option && isset($this->options[(int) $option->getId()]);
    }
}
