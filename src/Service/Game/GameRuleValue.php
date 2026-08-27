<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\GameFamily;

/** One rule of the catalogue after this formation's own settings for this period have been applied. */
final readonly class GameRuleValue
{
    public function __construct(
        public GameRuleDefinition $definition,
        public int $points,
        public ?int $weeklyCap,
        public bool $enabled,
    ) {
    }

    public function code(): string
    {
        return $this->definition->code;
    }

    public function family(): GameFamily
    {
        return $this->definition->family;
    }

    /** What one occasion adds to the denominator of its family, null when the family counts elsewhere. */
    public function possibleValue(): ?int
    {
        return $this->definition->possibleValue;
    }
}
