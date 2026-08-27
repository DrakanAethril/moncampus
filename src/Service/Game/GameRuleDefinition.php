<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\GameFamily;

/**
 * One line of the barème as the catalogue states it: what it pays, how often at most, and which
 * family it falls into.
 *
 * $weeklyCap is what stops a rule that is *repeatable at will* from being emitted in a batch on a
 * Sunday evening (§5.3). A wiki revision and a job application are real gestures; without a cap
 * they are also a lever, which is why they are worth 5 points and capped at two a week rather than
 * being worth more and trusted.
 *
 * $possibleValue is what one occasion of this rule adds to the *denominator* of its family, and it
 * is nearly always null: only the work family counts its own deadlines. A rule with a null possible
 * pays into a family whose denominator comes from somewhere else - the attendance statement, or a
 * flat cap.
 */
final readonly class GameRuleDefinition
{
    public function __construct(
        public string $code,
        public GameFamily $family,
        public int $points,
        public ?int $weeklyCap = null,
        public ?int $possibleValue = null,
    ) {
    }

    public function labelKey(): string
    {
        return 'gameRule.'.$this->code;
    }
}
