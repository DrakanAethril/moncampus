<?php

declare(strict_types=1);

namespace App\Service\Equipment;

/**
 * What one journal line does to the three counters of its type. Summed over the whole journal, it
 * is also what the counters must hold - which is the recomputation.
 */
final readonly class EquipmentCounterDelta
{
    public function __construct(
        public int $available = 0,
        public int $inUse = 0,
        public int $onOrder = 0,
    ) {
    }

    public static function zero(): self
    {
        return new self();
    }

    public function plus(self $other): self
    {
        return new self(
            $this->available + $other->available,
            $this->inUse + $other->inUse,
            $this->onOrder + $other->onOrder,
        );
    }

    public function isZero(): bool
    {
        return 0 === $this->available && 0 === $this->inUse && 0 === $this->onOrder;
    }
}
