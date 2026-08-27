<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * One deadline of the work family: what it could have paid, and what it did.
 *
 * The pair matters more than either half. $maxPoints is what this deadline adds to the family's
 * **denominator** - which is why four deadlines honoured out of four are worth as much as twelve out
 * of twelve - and $ruleCode is what it credited, null when nothing was handed in. A deadline that
 * paid nothing is still counted, and that is exactly the difference between a rate and a total.
 */
final readonly class GameWorkDeadline
{
    public function __construct(
        public int $maxPoints,
        public \DateTimeImmutable $dueDate,
        public ?string $ruleCode = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?\DateTimeImmutable $occurredAt = null,
    ) {
    }

    public function isHonoured(): bool
    {
        return null !== $this->ruleCode;
    }
}
