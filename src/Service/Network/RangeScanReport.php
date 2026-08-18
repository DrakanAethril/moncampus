<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * What one scan of one range learned. Returned to the screen, to the command, and to the "save and
 * scan" button alike, so all three report the same thing in the same words.
 */
final readonly class RangeScanReport
{
    /**
     * @param list<AddressGap>        $gaps
     * @param list<DiscoveredAddress> $found addresses actually carried inside this range
     */
    public function __construct(
        public array $gaps,
        public array $found,
        public int $guestsRead,
        public int $freeCount,
        public int $capacity,
        public ?string $failure = null,
    ) {
    }

    public static function failed(string $reason): self
    {
        return new self([], [], 0, 0, 0, $reason);
    }

    public function conflictCount(): int
    {
        return \count(array_filter($this->gaps, static fn (AddressGap $gap): bool => AddressGap::CONFLICT === $gap->kind));
    }
}
